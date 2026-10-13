<?php

namespace Tests\Feature;

use App\Services\AutomatizacionService;
use App\Support\HoraLocal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Procesos automaticos (AutomatizacionService y los comandos programados en routes/console.php): periodo del mes,
 * compensaciones vencidas (RIT Art. 17), turnos cumplidos y aviso de faltas reiteradas (RIT Art. 23).
 */
class Nivel7AutomatizacionTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function servicio(): AutomatizacionService
    {
        return app(AutomatizacionService::class);
    }

    private function estado(string $codigo): int
    {
        return $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', $codigo);
    }

    private function dia(int $vinculo, string $fecha, string $estado): void
    {
        DB::table('Asistencia.AsistenciaDiaria')->insert(['VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado($estado), 'AsistenciaDiariaFecha' => $fecha]);
    }

    public function test_el_periodo_del_mes_se_abre_una_sola_vez(): void
    {
        DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => HoraLocal::hoy()->year, 'PeriodoAsistenciaMes' => HoraLocal::hoy()->month])->delete();

        $this->assertTrue($this->servicio()->abrirPeriodoDelMes());
        $this->assertFalse($this->servicio()->abrirPeriodoDelMes());
        $this->assertSame('ABIERTO', DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => HoraLocal::hoy()->year, 'PeriodoAsistenciaMes' => HoraLocal::hoy()->month])->value('PeriodoAsistenciaEstado'));
    }

    public function test_la_compensacion_con_fecha_limite_pasada_vence_y_la_consumida_no(): void
    {
        $v = $this->nuevoVinculo();
        $tipo = DB::table('Compensaciones.TipoCompensacion')->value('TipoCompensacionId');
        $ayer = HoraLocal::hoy()->subDay()->toDateString();
        $manana = HoraLocal::hoy()->addDay()->toDateString();
        $nueva = fn (string $estado, string $limite) => DB::table('Compensaciones.CompensacionHoraria')->insertGetId([
            'VinculoLaboralId' => $v, 'TipoCompensacionId' => $tipo, 'CompensacionHorariaHorasGeneradas' => 2,
            'CompensacionHorariaFechaLimite' => $limite, 'CompensacionHorariaEstado' => $estado,
        ], 'CompensacionHorariaId');
        $pendiente = $nueva('PENDIENTE', $ayer);
        $aprobada = $nueva('APROBADO', $ayer);
        $consumida = $nueva('CONSUMIDO', $ayer);
        $vigente = $nueva('APROBADO', $manana);

        $this->assertGreaterThanOrEqual(2, $this->servicio()->vencerCompensaciones());

        $estado = fn (int $id) => DB::table('Compensaciones.CompensacionHoraria')->where('CompensacionHorariaId', $id)->value('CompensacionHorariaEstado');
        $this->assertSame(['VENCIDO', 'VENCIDO', 'CONSUMIDO', 'APROBADO'], [$estado($pendiente), $estado($aprobada), $estado($consumida), $estado($vigente)]);
        $this->assertSame(0, $this->servicio()->vencerCompensaciones());
    }

    public function test_el_turno_con_asistencia_se_marca_cumplido_y_el_de_falta_no(): void
    {
        $v = $this->nuevoVinculo();
        $periodo = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-09-07', '2026-09-30');
        $pt = $this->programacionDeTrabajador($periodo, $v);
        $asistio = $this->turnoProgramado($pt, '2026-09-08');
        $falto = $this->turnoProgramado($pt, '2026-09-09');
        $this->dia($v, '2026-09-08', 'ASISTIO');
        $this->dia($v, '2026-09-09', 'FALTA');

        $this->servicio()->cumplirTurnos();

        $estado = fn (int $id) => DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $id)->value('TurnoProgramadoEstado');
        $this->assertSame(['CUMPLIDO', 'PROGRAMADO'], [$estado($asistio), $estado($falto)]);
    }

    public function test_cuatro_faltas_consecutivas_avisan_a_rrhh_una_sola_vez(): void
    {
        $v = $this->nuevoVinculo();
        $hoy = HoraLocal::hoy();
        foreach ([8, 7, 6, 5] as $atras) {
            $this->dia($v, $hoy->subDays($atras)->toDateString(), 'FALTA');
        }
        $usuario = $this->usuarioConRol('RRHH_RED');

        $this->assertGreaterThanOrEqual(1, $this->servicio()->detectarFaltasReiteradas());

        $avisos = DB::table('Soporte.Notificacion')->where(['UsuarioId' => $usuario, 'NotificacionTipo' => AutomatizacionService::TIPO_ALERTA_FALTAS])->get();
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('4 faltas injustificadas consecutivas', $avisos[0]->NotificacionMensaje);
        $this->assertSame(0, $this->servicio()->detectarFaltasReiteradas());
    }

    public function test_tres_faltas_o_faltas_justificadas_no_generan_aviso(): void
    {
        $v = $this->nuevoVinculo();
        $hoy = HoraLocal::hoy();
        foreach ([6, 5, 4] as $atras) {
            $this->dia($v, $hoy->subDays($atras)->toDateString(), 'FALTA');
        }
        foreach ([3, 2, 1] as $atras) {
            $this->dia($v, $hoy->subDays($atras)->toDateString(), 'FALTA_JUST');
        }
        $usuario = $this->usuarioConRol('RRHH_RED');

        $this->servicio()->detectarFaltasReiteradas();

        $this->assertSame(0, DB::table('Soporte.Notificacion')->where(['UsuarioId' => $usuario, 'NotificacionTipo' => AutomatizacionService::TIPO_ALERTA_FALTAS])->where('NotificacionTitulo', 'like', '%'.DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $v)->value('VinculoLaboralCodigo').'%')->count());
    }

    public function test_los_comandos_programados_corren_sin_error(): void
    {
        $this->artisan('asistencia:calcular', ['--dias' => 1])->assertExitCode(0);
        $this->artisan('consolidado:actualizar')->assertExitCode(0);
        $this->artisan('sistema:mantenimiento')->assertExitCode(0);
    }

    private function usuarioConRol(string $rol): int
    {
        $usuario = DB::table('Seguridad.Usuario')->insertGetId([
            'TrabajadorId' => DB::table('Personal.Trabajador as t')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('Seguridad.Usuario as u')->whereColumn('u.TrabajadorId', 't.TrabajadorId'))->orderBy('t.TrabajadorId')->value('t.TrabajadorId'), 'UsuarioNombre' => 'auto'.uniqid(), 'UsuarioPasswordHash' => 'x', 'UsuarioEstado' => 1,
        ], 'UsuarioId');
        DB::table('Seguridad.UsuarioRol')->insert([
            'UsuarioId' => $usuario, 'RolId' => DB::table('Seguridad.Rol')->where('RolCodigo', $rol)->value('RolId'), 'UsuarioRolFechaInicio' => '2026-01-01', 'UsuarioRolEstado' => 1,
        ]);

        return (int) $usuario;
    }
}
