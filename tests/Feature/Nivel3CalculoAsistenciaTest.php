<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Motor de calculo de la asistencia diaria (POST /api/asistencia-diaria/calcular): convierte marcaciones y solicitudes
 * aprobadas, frente al turno programado o al horario asignado, en la asistencia del dia (RIT, Art. 16, 17, 19, 20, 22 y 23).
 * No es un CRUD: no extiende CrudModulosTestCase, solo prueba las reglas de negocio del calculo.
 */
class Nivel3CalculoAsistenciaTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private const LUNES = '2026-09-07';

    // ------------------------------------------------------------------ armado de escenarios

    /** Programacion publicada del 7 al 30 de setiembre de 2026, periodo de asistencia abierto (una por prueba, compartida por todos sus vinculos). */
    private function programacionDeSetiembre(): int
    {
        return $this->recordar('programacion-setiembre', fn () => $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-09-07', '2026-09-30'));
    }

    /** Vinculo con un turno programado ese dia. @param array<string,mixed> $vinculo columnas del vinculo a pisar */
    private function programado(string $fecha, string $turno = 'M', array $vinculo = []): int
    {
        $id = $vinculo === [] && $turno !== 'N' && $turno !== 'G12-D' ? $this->nuevoVinculo() : $this->nuevoVinculoDeGuardia($vinculo);

        return $this->conTurno($id, $fecha, $turno);
    }

    /** Agrega un turno programado del vinculo (crea su programacion de trabajador la primera vez). */
    private function conTurno(int $vinculo, string $fecha, string $turno): int
    {
        $periodo = $this->programacionDeSetiembre();
        $pt = DB::table('Programacion.ProgramacionTrabajador')->where(['ProgramacionPeriodoId' => $periodo, 'VinculoLaboralId' => $vinculo])->value('ProgramacionTrabajadorId')
            ?? $this->programacionDeTrabajador($periodo, $vinculo);
        $this->turnoProgramado((int) $pt, $fecha, $turno);

        return $vinculo;
    }

    private function marca(int $vinculo, string $tipo, string $momento): void
    {
        DB::table('Asistencia.Marcacion')->insert([
            'VinculoLaboralId' => $vinculo, 'MarcacionTipo' => $tipo, 'MarcacionFechaHora' => $momento,
            'MetodoMarcacionId' => $this->idPorCodigo('Biometria.MetodoMarcacion', 'MetodoMarcacionId', 'MetodoMarcacionCodigo', 'ROSTRO'),
        ]);
    }

    private function jornada(int $vinculo, string $entrada, ?string $salida): void
    {
        $this->marca($vinculo, 'ENTRADA', $entrada);
        if ($salida !== null) {
            $this->marca($vinculo, 'SALIDA', $salida);
        }
    }

    /** @return array<string,mixed> el resumen de la corrida */
    private function calcular(int $vinculo, string $desde = self::LUNES, ?string $hasta = null, array $extra = []): array
    {
        return $this->postJson('/api/asistencia-diaria/calcular', ['FechaInicio' => $desde, 'FechaFin' => $hasta ?? $desde, 'VinculoLaboralId' => $vinculo] + $extra)
            ->assertOk()->json();
    }

    /** @return object la fila de asistencia diaria con el codigo de su estado */
    private function dia(int $vinculo, string $fecha = self::LUNES): object
    {
        $fila = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->where(['a.VinculoLaboralId' => $vinculo])->whereDate('a.AsistenciaDiariaFecha', $fecha)->first(['a.*', 'e.EstadoAsistenciaCodigo as codigo']);
        $this->assertNotNull($fila, "No se generó la asistencia de {$fecha}.");

        return $fila;
    }

    private function estado(string $codigo): int
    {
        return $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', $codigo);
    }

    private function papeleta(int $vinculo, string $fecha, string $tipo, array $extra = []): void
    {
        DB::table('Solicitudes.Papeleta')->insert($extra + [
            'VinculoLaboralId' => $vinculo, 'TipoPapeletaId' => $this->idPorCodigo('Solicitudes.TipoPapeleta', 'TipoPapeletaId', 'TipoPapeletaCodigo', $tipo),
            'PapeletaFecha' => $fecha, 'PapeletaEstado' => 'APROBADO',
        ]);
    }

    // ------------------------------------------------------------------ marcaciones contra el turno

    public function test_marcacion_a_tiempo_es_asistencia_con_los_minutos_del_turno(): void
    {
        $v = $this->programado(self::LUNES);   // turno de manana 07:30 a 13:30
        $this->jornada($v, '2026-09-07 07:25:10', '2026-09-07 13:31:00');

        $r = $this->calcular($v);

        $this->assertSame(1, $r['creados']);
        $d = $this->dia($v);
        $this->assertSame('ASISTIO', $d->codigo);
        $this->assertSame(0, (int) $d->AsistenciaDiariaMinutosTardanza);
        $this->assertSame(360, (int) $d->AsistenciaDiariaMinutosTrabajados);
        $this->assertSame(['2026-09-07 07:25:10', '2026-09-07 13:31:00'], [substr($d->AsistenciaDiariaHoraEntrada, 0, 19), substr($d->AsistenciaDiariaHoraSalida, 0, 19)]);   // las marcas quedan en la fila
        $this->assertNotNull($d->TurnoProgramadoId);
        $this->assertSame('CALCULADO', $d->AsistenciaDiariaOrigen);
    }

    public function test_la_escala_de_tardanza_del_art_22(): void
    {
        // [hora de ingreso, estado, minutos de descuento]: tolerancia hasta 07:35:59; luego 10, 20 y 30; desde 08:01 es inasistencia.
        $casos = [
            ['07:35:59', 'ASISTIO', 0], ['07:36:00', 'TARDANZA', 10], ['07:40:30', 'TARDANZA', 10], ['07:41:00', 'TARDANZA', 20],
            ['07:50:59', 'TARDANZA', 20], ['07:51:00', 'TARDANZA', 30], ['08:00:59', 'TARDANZA', 30], ['08:01:00', 'FALTA', 0],
        ];
        $v = $this->nuevoVinculo();
        foreach ($casos as $i => [$hora]) {
            $fecha = CarbonImmutable::parse(self::LUNES)->addDays($i)->toDateString();
            $this->conTurno($v, $fecha, 'M');
            $this->jornada($v, "{$fecha} {$hora}", "{$fecha} 13:30:00");
        }

        $this->calcular($v, self::LUNES, '2026-09-14');

        foreach ($casos as $i => [$hora, $estado, $descuento]) {
            $d = $this->dia($v, CarbonImmutable::parse(self::LUNES)->addDays($i)->toDateString());
            $this->assertSame($estado, $d->codigo, "Ingreso {$hora}");
            $this->assertSame($descuento, (int) $d->AsistenciaDiariaMinutosTardanza, "Ingreso {$hora}");
        }
        // La inasistencia por tardanza no es una tardanza: pierde la jornada completa.
        $this->assertSame(360, (int) $this->dia($v, '2026-09-14')->AsistenciaDiariaMinutosFalta);
    }

    public function test_sin_tolerancia_para_serums_equivalente_e_interno(): void
    {
        $serums = $this->programado(self::LUNES, 'M', ['CondicionLaboralId' => $this->idPorCodigo('Personal.CondicionLaboral', 'CondicionLaboralId', 'CondicionLaboralCodigo', 'SERUMS_EQUIV')]);
        $this->jornada($serums, '2026-09-07 07:33:00', '2026-09-07 13:30:00');

        $this->calcular($serums);

        $d = $this->dia($serums);
        $this->assertSame('TARDANZA', $d->codigo);
        $this->assertSame(3, (int) $d->AsistenciaDiariaMinutosTardanza);   // los minutos reales, a devolver
    }

    public function test_sin_ninguna_marca_es_falta_y_la_guardia_no_realizada_equivale_a_dos_dias(): void
    {
        $dia = $this->programado(self::LUNES);
        $guardia = $this->programado(self::LUNES, 'G12-D');

        $this->calcular($dia);
        $this->calcular($guardia);

        $falta = $this->dia($dia);
        $this->assertSame('FALTA', $falta->codigo);
        $this->assertSame(360, (int) $falta->AsistenciaDiariaMinutosFalta);
        $noRealizada = $this->dia($guardia);
        $this->assertSame('FALTA_GUARDIA', $noRealizada->codigo);
        $this->assertSame(1440, (int) $noRealizada->AsistenciaDiariaMinutosFalta);
    }

    public function test_omitir_una_marca_es_inasistencia_injustificada(): void
    {
        $sinSalida = $this->programado(self::LUNES);
        $this->jornada($sinSalida, '2026-09-07 07:30:00', null);
        $sinEntrada = $this->programado(self::LUNES);
        $this->marca($sinEntrada, 'SALIDA', '2026-09-07 13:30:00');

        $this->calcular($sinSalida);
        $this->calcular($sinEntrada);

        $this->assertSame('OMISION_MARCA', $this->dia($sinSalida)->codigo);
        $this->assertSame('OMISION_MARCA', $this->dia($sinEntrada)->codigo);
        $this->assertStringContainsString('salida', $this->dia($sinSalida)->AsistenciaDiariaObservacion);
    }

    public function test_salida_antes_de_hora_es_inasistencia_salvo_que_una_papeleta_la_autorice(): void
    {
        $sinPapeleta = $this->programado(self::LUNES);
        $this->jornada($sinPapeleta, '2026-09-07 07:30:00', '2026-09-07 12:00:00');
        $conPapeleta = $this->programado(self::LUNES);
        $this->jornada($conPapeleta, '2026-09-07 07:30:00', '2026-09-07 12:00:00');
        $this->papeleta($conPapeleta, self::LUNES, 'PERM_SALUD', ['PapeletaHoraSalida' => '12:00:00']);   // sin retorno: rige hasta el cierre

        $this->calcular($sinPapeleta);
        $this->calcular($conPapeleta);

        $this->assertSame('FALTA', $this->dia($sinPapeleta)->codigo);
        $d = $this->dia($conPapeleta);
        $this->assertSame('ASISTIO', $d->codigo);
        $this->assertSame(270, (int) $d->AsistenciaDiariaMinutosTrabajados);
    }

    public function test_una_papeleta_que_cubre_el_ingreso_justifica_el_atraso(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 09:00:00', '2026-09-07 13:30:00');
        $this->papeleta($v, self::LUNES, 'PERM_SALUD', ['PapeletaHoraSalida' => '07:30:00', 'PapeletaHoraRetorno' => '09:00:00']);

        $this->calcular($v);

        $d = $this->dia($v);
        $this->assertSame('ASISTIO', $d->codigo);
        $this->assertSame(0, (int) $d->AsistenciaDiariaMinutosTardanza);
    }

    public function test_guardia_nocturna_cruza_la_medianoche(): void
    {
        $v = $this->programado(self::LUNES, 'N');   // 19:30 a 07:30 del dia siguiente
        $this->jornada($v, '2026-09-07 19:28:00', '2026-09-08 07:40:00');

        $this->calcular($v);

        $d = $this->dia($v);
        $this->assertSame('ASISTIO', $d->codigo);
        $this->assertSame(720, (int) $d->AsistenciaDiariaMinutosTrabajados);
        $this->assertSame(0, (int) $d->AsistenciaDiariaMinutosExtra);   // esperar el relevo no es sobretiempo
    }

    public function test_dos_turnos_el_mismo_dia_se_evaluan_como_una_jornada(): void
    {
        $v = $this->programado(self::LUNES, 'M');
        $this->conTurno($v, self::LUNES, 'T');
        $this->jornada($v, '2026-09-07 07:30:00', '2026-09-07 19:30:00');

        $this->calcular($v);

        $d = $this->dia($v);
        $this->assertSame('ASISTIO', $d->codigo);
        $this->assertSame(720, (int) $d->AsistenciaDiariaMinutosTrabajados);
    }

    // ------------------------------------------------------------------ que se espera ese dia

    public function test_un_dia_libre_del_programado_no_genera_asistencia_pero_el_feriado_si_obliga(): void
    {
        $v = $this->programado('2026-09-08');   // trabaja el martes; el lunes es su dia libre
        DB::table('Soporte.CalendarioNoLaborable')->updateOrInsert(['CalendarioNoLaborableFecha' => '2026-09-08', 'MicroredId' => null], ['CalendarioNoLaborableTipo' => 'FERIADO']);

        $r = $this->calcular($v, self::LUNES, '2026-09-08');

        $this->assertSame(1, $r['evaluados']);   // solo el martes
        $this->assertSame('FALTA', $this->dia($v, '2026-09-08')->codigo);   // el feriado no exime al programado (Art. 16.b)
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->whereDate('AsistenciaDiariaFecha', self::LUNES)->count());
    }

    public function test_el_horario_asignado_rige_sin_programacion_y_el_feriado_no_laborable(): void
    {
        $v = $this->nuevoVinculo();
        DB::table('Personal.AsignacionHorario')->insert(['VinculoLaboralId' => $v, 'HorarioId' => $this->idPorCodigo('Configuracion.Horario', 'HorarioId', 'HorarioCodigo', 'HOR-ADM-LV'), 'AsignacionHorarioFechaInicio' => '2026-01-01']);
        DB::table('Soporte.CalendarioNoLaborable')->updateOrInsert(['CalendarioNoLaborableFecha' => '2026-09-08', 'MicroredId' => null], ['CalendarioNoLaborableTipo' => 'FERIADO']);
        $this->jornada($v, '2026-09-07 07:30:00', '2026-09-07 15:30:00');

        // lunes trabajado, martes feriado, sabado y domingo sin horario.
        $r = $this->calcular($v, self::LUNES, '2026-09-13');

        $this->assertSame(['ASISTIO', 'NO_LABORABLE', 'FALTA', 'FALTA', 'FALTA'], DB::table('Asistencia.AsistenciaDiaria as a')
            ->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')->where('a.VinculoLaboralId', $v)
            ->orderBy('a.AsistenciaDiariaFecha')->pluck('e.EstadoAsistenciaCodigo')->all());
        $this->assertSame(5, $r['creados']);
        $this->assertNull($this->dia($v)->TurnoProgramadoId);
        $this->assertSame(480, (int) $this->dia($v)->AsistenciaDiariaMinutosTrabajados);   // el refrigerio ni se marca ni se descuenta
    }

    public function test_una_programacion_en_borrador_no_genera_obligacion(): void
    {
        $v = $this->nuevoVinculo();
        $periodo = $this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2026-09-02');
        $this->turnoProgramado($this->programacionDeTrabajador($periodo, $v), '2026-09-08', 'M');

        $r = $this->calcular($v, '2026-09-08');

        $this->assertSame(0, $r['evaluados']);
    }

    // ------------------------------------------------------------------ solicitudes aprobadas

    public function test_licencia_descanso_medico_papeleta_de_dia_completo_y_vacaciones_cubren_el_dia(): void
    {
        $licencia = $this->programado(self::LUNES);
        DB::table('Solicitudes.Licencia')->insert([
            'VinculoLaboralId' => $licencia, 'TipoLicenciaId' => $this->idPorCodigo('Solicitudes.TipoLicencia', 'TipoLicenciaId', 'TipoLicenciaCodigo', 'FAMILIAR'),
            'LicenciaFechaInicio' => '2026-09-05', 'LicenciaFechaFin' => '2026-09-09', 'LicenciaEstado' => 'APROBADO',
        ]);
        $descanso = $this->programado(self::LUNES);
        DB::table('Solicitudes.DescansoMedico')->insert(['VinculoLaboralId' => $descanso, 'DescansoMedicoFechaInicio' => '2026-09-07', 'DescansoMedicoFechaFin' => '2026-09-11', 'DescansoMedicoEstado' => 'APROBADO']);
        $comision = $this->programado(self::LUNES);
        $this->papeleta($comision, self::LUNES, 'COMISION', ['PapeletaEsDiaCompleto' => 1]);
        $vacaciones = $this->programado(self::LUNES);
        $rol = $this->rolVacacional($this->periodoVacacional($vacaciones), '2026-09-02', 15);
        DB::table('Vacaciones.GoceVacacional')->insert(['RolVacacionalId' => $rol, 'GoceVacacionalFechaInicio' => '2026-09-02', 'GoceVacacionalFechaFin' => '2026-09-16', 'GoceVacacionalDias' => 15, 'GoceVacacionalEstado' => 'APROBADO']);
        $pendiente = $this->programado(self::LUNES);   // una licencia sin aprobar no cubre
        DB::table('Solicitudes.Licencia')->insert([
            'VinculoLaboralId' => $pendiente, 'TipoLicenciaId' => $this->idPorCodigo('Solicitudes.TipoLicencia', 'TipoLicenciaId', 'TipoLicenciaCodigo', 'FAMILIAR'),
            'LicenciaFechaInicio' => '2026-09-07', 'LicenciaFechaFin' => '2026-09-07', 'LicenciaEstado' => 'PENDIENTE',
        ]);

        foreach ([$licencia, $descanso, $comision, $vacaciones, $pendiente] as $v) {
            $this->calcular($v);
        }

        $this->assertSame('LICENCIA', $this->dia($licencia)->codigo);
        $this->assertSame('DESCANSO_MED', $this->dia($descanso)->codigo);
        $this->assertSame('COMISION', $this->dia($comision)->codigo);
        $this->assertSame('VACACIONES', $this->dia($vacaciones)->codigo);
        $this->assertSame('FALTA', $this->dia($pendiente)->codigo);
        $this->assertSame(0, (int) $this->dia($licencia)->AsistenciaDiariaMinutosFalta);
    }

    public function test_una_justificacion_aprobada_convierte_la_falta_en_justificada_y_se_enlaza(): void
    {
        $v = $this->programado(self::LUNES);
        $justificacion = (int) DB::table('Asistencia.JustificacionFalta')->insertGetId([
            'VinculoLaboralId' => $v, 'ConceptoJustificacionId' => $this->idPorCodigo('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', 'ConceptoJustificacionCodigo', 'EMERGENCIA'),
            'UsuarioRegistroId' => $this->usuarioId(), 'JustificacionFaltaFechaInicio' => '2026-09-07', 'JustificacionFaltaFechaFin' => '2026-09-07', 'JustificacionFaltaEstado' => 'APROBADO',
        ], 'JustificacionFaltaId');

        $this->calcular($v);

        $d = $this->dia($v);
        $this->assertSame('FALTA_JUST', $d->codigo);
        $this->assertSame($justificacion, (int) $d->JustificacionFaltaId);
    }

    public function test_aprobar_una_justificacion_tambien_justifica_la_omision_de_marca(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 07:30:00', null);
        $this->calcular($v);
        $id = $this->postJson('/api/justificaciones-falta', [
            'VinculoLaboralId' => $v, 'ConceptoJustificacionId' => $this->idPorCodigo('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', 'ConceptoJustificacionCodigo', 'OLVIDO_MARCA'),
            'UsuarioRegistroId' => $this->usuarioId(), 'JustificacionFaltaFechaInicio' => '2026-09-07', 'JustificacionFaltaFechaFin' => '2026-09-07',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/justificaciones-falta/{$id}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertOk()->assertJsonPath('asistencias_actualizadas', 1);

        $this->assertSame('FALTA_JUST', $this->dia($v)->codigo);
        // Y recalcular no le quita la justificacion.
        $this->calcular($v);
        $this->assertSame('FALTA_JUST', $this->dia($v)->codigo);
    }

    // ------------------------------------------------------------------ sobretiempo

    public function test_el_sobretiempo_solo_se_reconoce_con_compensacion_horaria_autorizada(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 07:30:00', '2026-09-07 15:00:00');   // hora y media despues del turno

        $this->calcular($v);
        $sin = $this->dia($v);
        $this->assertSame(0, (int) $sin->AsistenciaDiariaMinutosExtra);
        $this->assertStringContainsString('Art. 17', $sin->AsistenciaDiariaObservacion);

        DB::table('Compensaciones.CompensacionHoraria')->insert([
            'VinculoLaboralId' => $v, 'TipoCompensacionId' => $this->idPorCodigo('Compensaciones.TipoCompensacion', 'TipoCompensacionId', 'TipoCompensacionCodigo', 'HORA_EXTRA'),
            'AsistenciaDiariaId' => $sin->AsistenciaDiariaId, 'CompensacionHorariaHorasGeneradas' => 1, 'CompensacionHorariaEstado' => 'APROBADO',
        ]);
        $this->calcular($v);

        $this->assertSame(60, (int) $this->dia($v)->AsistenciaDiariaMinutosExtra);   // lo autorizado, no lo que se quedo de mas
    }

    // ------------------------------------------------------------------ que se respeta

    public function test_calcular_es_idempotente_y_recoge_las_marcaciones_nuevas(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 07:30:00', '2026-09-07 13:30:00');

        $this->assertSame(1, $this->calcular($v)['creados']);
        $segunda = $this->calcular($v);
        $this->assertSame(0, $segunda['creados']);
        $this->assertSame(1, $segunda['sin_cambios']);

        // Un ajuste de la marcacion de entrada (07:50) cambia el resultado en la siguiente corrida.
        DB::table('Asistencia.Marcacion')->where(['VinculoLaboralId' => $v, 'MarcacionTipo' => 'ENTRADA'])->update(['MarcacionFechaHora' => '2026-09-07 07:50:00']);
        $tercera = $this->calcular($v);
        $this->assertSame(1, $tercera['actualizados']);
        $this->assertSame(20, (int) $this->dia($v)->AsistenciaDiariaMinutosTardanza);
        $this->assertSame(1, DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->count());
    }

    public function test_las_filas_digitadas_a_mano_se_respetan_salvo_que_se_pida_sobrescribirlas(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 07:30:00', '2026-09-07 13:30:00');
        DB::table('Asistencia.AsistenciaDiaria')->insert(['VinculoLaboralId' => $v, 'EstadoAsistenciaId' => $this->estado('FALTA'), 'AsistenciaDiariaFecha' => self::LUNES]);

        $r = $this->calcular($v);
        $this->assertSame(1, $r['omitidos_manuales']);
        $this->assertSame('FALTA', $this->dia($v)->codigo);

        $this->assertSame(1, $this->calcular($v, extra: ['SobrescribirManuales' => true])['actualizados']);
        $this->assertSame('ASISTIO', $this->dia($v)->codigo);
    }

    public function test_corregir_a_mano_una_fila_calculada_la_fija(): void
    {
        $v = $this->programado(self::LUNES);
        $this->calcular($v);   // sin marcas: falta
        $fila = $this->dia($v);

        $this->patchJson("/api/asistencia-diaria/{$fila->AsistenciaDiariaId}", ['EstadoAsistenciaId' => $this->estado('ASISTIO'), 'AsistenciaDiariaObservacion' => 'Corregida por RRHH'])->assertOk();

        $this->assertSame('MANUAL', $this->dia($v)->AsistenciaDiariaOrigen);
        $this->assertSame(1, $this->calcular($v)['omitidos_manuales']);
        $this->assertSame('ASISTIO', $this->dia($v)->codigo);
    }

    public function test_un_periodo_de_asistencia_cerrado_no_se_toca(): void
    {
        $v = $this->nuevoVinculo();
        $periodo = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-08-01');   // agosto de 2026: periodo de asistencia CERRADO
        $this->turnoProgramado($this->programacionDeTrabajador($periodo, $v), '2026-08-10', 'M');

        $r = $this->calcular($v, '2026-08-10');

        $this->assertSame(1, $r['omitidos_periodo_cerrado']);
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->count());
    }

    public function test_el_dia_en_curso_queda_pendiente_hasta_que_termina_el_turno(): void
    {
        $v = $this->programado(self::LUNES);
        $this->jornada($v, '2026-09-07 07:30:00', null);

        // Lunes 11:00: el turno (hasta 13:30) sigue en curso y falta la salida: aun no es omision.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 11:00:00', 'America/Lima'));
        $enCurso = $this->calcular($v);
        $this->assertSame(1, $enCurso['pendientes']);
        $this->assertSame(0, $enCurso['creados']);

        // A las 17:00 ya paso el margen de cierre: la salida omitida es inasistencia.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 17:00:00', 'America/Lima'));
        $this->assertSame(1, $this->calcular($v)['creados']);
        $this->assertSame('OMISION_MARCA', $this->dia($v)->codigo);
    }

    public function test_las_fechas_futuras_se_ignoran(): void
    {
        $v = $this->programado(self::LUNES);
        $this->travelTo(CarbonImmutable::parse('2026-09-06 09:00:00', 'America/Lima'));

        $r = $this->calcular($v);

        $this->assertSame(0, $r['evaluados']);
    }

    // ------------------------------------------------------------------ consolidado y validacion

    public function test_el_consolidado_cuenta_la_guardia_no_realizada_como_dos_faltas_y_la_omision_como_falta(): void
    {
        $v = $this->nuevoVinculo();
        foreach ([['2026-09-21', 'FALTA_GUARDIA'], ['2026-09-22', 'OMISION_MARCA'], ['2026-09-23', 'FALTA'], ['2026-09-24', 'ASISTIO']] as [$fecha, $estado]) {
            DB::table('Asistencia.AsistenciaDiaria')->insert(['VinculoLaboralId' => $v, 'EstadoAsistenciaId' => $this->estado($estado), 'AsistenciaDiariaFecha' => $fecha]);
        }

        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $this->periodoDeAsistenciaId(9), 'VinculoLaboralId' => $v])->assertOk()->assertJsonPath('generados', 1);

        $c = DB::table('Consolidacion.ConsolidadoAsistencia')->where('VinculoLaboralId', $v)->first();
        $this->assertEquals(4, $c->ConsolidadoAsistenciaDiasFalta);   // 2 + 1 + 1
        $this->assertEquals(1, $c->ConsolidadoAsistenciaDiasTrabajados);
    }

    public function test_el_rango_se_valida(): void
    {
        $this->postJson('/api/asistencia-diaria/calcular', [])->assertStatus(422)->assertJsonValidationErrors(['FechaInicio', 'FechaFin']);
        $this->postJson('/api/asistencia-diaria/calcular', ['FechaInicio' => '2026-09-11', 'FechaFin' => '2026-09-10'])->assertStatus(422)->assertJsonValidationErrors(['FechaFin']);
        $this->postJson('/api/asistencia-diaria/calcular', ['FechaInicio' => '2026-01-01', 'FechaFin' => '2026-06-30'])->assertStatus(422)->assertJsonValidationErrors(['FechaFin']);
        $this->postJson('/api/asistencia-diaria/calcular', ['FechaInicio' => '2026-09-02', 'FechaFin' => '2026-09-03', 'VinculoLaboralId' => 999999])->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
    }
}
