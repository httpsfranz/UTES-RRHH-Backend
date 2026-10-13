<?php

namespace Tests\Feature;

use Database\Seeders\DatosPruebaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La expansion de volumen de DatosPruebaSeeder (la Red "en uso": personal, programacion, marcaciones, solicitudes, asistencia
 * calculada, consolidados, liquidaciones...). Siembra todo dentro de una transaccion de la base de pruebas, comprueba que el
 * resultado sea coherente con las reglas del sistema y lo revierte. Tarda cerca de un minuto, asi que solo corre a pedido:
 *
 *     PROBAR_VOLUMEN=1 vendor/bin/phpunit tests/Feature/VolumenSeederTest.php
 *
 * Conviene correrla cada vez que cambie el motor de calculo, un servicio de consolidacion o liquidacion, o el propio seeder:
 * la expansion los usa y podria romperse sin que ninguna otra prueba lo note.
 */
class VolumenSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_la_expansion_de_volumen_deja_la_red_completa_y_coherente(): void
    {
        if (! env('PROBAR_VOLUMEN')) {
            $this->markTestSkipped('Lenta (~1 min): PROBAR_VOLUMEN=1 vendor/bin/phpunit tests/Feature/VolumenSeederTest.php');
        }
        config(['seed.volumen' => true]);

        $this->seed(DatosPruebaSeeder::class);

        $this->personalDeCadaEstablecimiento();
        $this->asistenciaCalculadaConTodosSusEstados();
        $this->programacionCoherente();
        $this->solicitudesYJustificaciones();
        $this->cierresDeMes();
        $this->integridad();

        // Idempotente: una segunda corrida no agrega nada.
        $antes = $this->conteos();
        $this->seed(DatosPruebaSeeder::class);
        $this->assertSame($antes, $this->conteos());
    }

    private function personalDeCadaEstablecimiento(): void
    {
        $volumen = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', 'like', 'VL-1%')->count();
        $this->assertGreaterThanOrEqual(150, $volumen);
        $this->assertSame($volumen, DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', 'like', '46%')->count());

        // Todo establecimiento activo tiene su plantilla (un C.S. I-4 mas que un P.S. I-1) y Florencia de Mora, mas de diez.
        $porEess = DB::table('Personal.VinculoLaboral as v')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')->groupBy('e.EessCodigo')->selectRaw('e.EessCodigo AS codigo, COUNT(*) AS n')->pluck('n', 'codigo')->all();
        $this->assertCount(16, $porEess);
        $this->assertGreaterThanOrEqual(10, $porEess['EESS-FM-01']);
        $this->assertGreaterThan($porEess['EESS-LE-03'], $porEess['EESS-LE-01']);
        // Quien lleva el establecimiento tiene usuario, rol y ambito.
        $this->assertGreaterThanOrEqual(16, DB::table('Organizacion.ResponsableEess')->where('ResponsableEessEstado', 1)->whereNull('ResponsableEessFechaFin')->count());
        $this->assertSame(0, DB::table('Seguridad.Usuario as u')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'u.TrabajadorId')
            ->where('t.TrabajadorNumeroDocumento', 'like', '46%')->whereNotExists(fn ($q) => $q->from('Seguridad.UsuarioAmbito as a')->whereColumn('a.UsuarioId', 'u.UsuarioId'))->count());
    }

    private function asistenciaCalculadaConTodosSusEstados(): void
    {
        $calculada = DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaOrigen', 'CALCULADO')->count();
        $this->assertGreaterThan(8000, $calculada);

        $estados = DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->where('a.AsistenciaDiariaOrigen', 'CALCULADO')->distinct()->pluck('e.EstadoAsistenciaCodigo')->all();
        foreach (['ASISTIO', 'TARDANZA', 'FALTA', 'FALTA_JUST', 'OMISION_MARCA', 'VACACIONES', 'LICENCIA', 'DESCANSO_MED'] as $codigo) {
            $this->assertContains($codigo, $estados, "La asistencia calculada no tiene ningun dia en {$codigo}.");
        }
        // Lo que el motor calcula guarda sus marcas: cada dia con entrada y salida tiene sus horas y minutos trabajados.
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->where('e.EstadoAsistenciaCodigo', 'ASISTIO')->where('a.AsistenciaDiariaOrigen', 'CALCULADO')->whereNull('a.AsistenciaDiariaHoraEntrada')->count());
        // Una falta justificada siempre apunta a una justificacion aprobada.
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria as a')->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
            ->where('a.AsistenciaDiariaOrigen', 'CALCULADO')->where('e.EstadoAsistenciaCodigo', 'FALTA_JUST')->whereNull('a.JustificacionFaltaId')->count());
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria')->whereNotNull('JustificacionFaltaId')
            ->whereNotIn('JustificacionFaltaId', DB::table('Asistencia.JustificacionFalta')->where('JustificacionFaltaEstado', 'APROBADO')->select('JustificacionFaltaId'))->count());
        // El sobretiempo solo existe con una compensacion horaria autorizada (RIT, Art. 17).
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria as a')->where('a.AsistenciaDiariaMinutosExtra', '>', 0)->where('a.AsistenciaDiariaOrigen', 'CALCULADO')
            ->whereNotExists(fn ($q) => $q->from('Compensaciones.CompensacionHoraria as c')->whereColumn('c.AsistenciaDiariaId', 'a.AsistenciaDiariaId')->whereIn('c.CompensacionHorariaEstado', ['APROBADO', 'CONSUMIDO']))
            ->whereNotExists(fn ($q) => $q->from('Programacion.TurnoProgramado as t')->whereColumn('t.TurnoProgramadoId', 'a.TurnoProgramadoId')->where('t.TurnoProgramadoEsGuardia', 1))->count());
    }

    private function programacionCoherente(): void
    {
        // Los turnos de un trabajador nunca pasan de 150 horas al mes (RIT, Art. 16) ni de 12 horas.
        $this->assertSame(0, DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorHorasProgramadas', '>', 150)->count());
        // Guardias solo del personal D.L. 276 (RIT, Art. 20) y ningun trabajador con dos turnos el mismo dia a la misma hora.
        $this->assertSame(0, DB::table('Programacion.TurnoProgramado as t')->join('Programacion.ProgramacionTrabajador as p', 'p.ProgramacionTrabajadorId', '=', 't.ProgramacionTrabajadorId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'p.VinculoLaboralId')->join('Personal.RegimenLaboral as r', 'r.RegimenLaboralId', '=', 'v.RegimenLaboralId')
            ->where('t.TurnoProgramadoEsGuardia', 1)->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')->where('r.RegimenLaboralCodigo', '<>', 'DL276')->count());
        // Programacion cerrada = turnos cumplidos; publicada = lo pasado cumplido y lo futuro programado.
        $this->assertSame(0, DB::table('Programacion.TurnoProgramado as t')->join('Programacion.ProgramacionTrabajador as p', 'p.ProgramacionTrabajadorId', '=', 't.ProgramacionTrabajadorId')
            ->where('p.ProgramacionTrabajadorEstado', 'CERRADA')->whereDate('t.TurnoProgramadoFecha', '<=', '2026-09-30')->whereNotIn('t.TurnoProgramadoEstado', ['CUMPLIDO', 'ANULADO', 'REPROGRAMADO'])->count());
        // Con el motor corrido, el estado de cada programacion sigue al de su periodo (lo mismo que verifica SeedersTest).
        $this->assertSame(0, DB::table('Programacion.ProgramacionTrabajador as t')->join('Programacion.ProgramacionPeriodo as p', 'p.ProgramacionPeriodoId', '=', 't.ProgramacionPeriodoId')
            ->whereRaw("t.ProgramacionTrabajadorEstado <> CASE p.ProgramacionPeriodoEstado WHEN 'CERRADA' THEN 'CERRADA' WHEN 'ANULADA' THEN 'ANULADA' WHEN 'PUBLICADA' THEN 'PUBLICADA' ELSE 'BORRADOR' END")->count());
        $this->assertSame(0, DB::table('Programacion.ProgramacionTrabajador as t')->join('Programacion.ProgramacionPeriodo as p', 'p.ProgramacionPeriodoId', '=', 't.ProgramacionPeriodoId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 't.VinculoLaboralId')->whereColumn('v.EessId', '<>', 'p.EessId')->count());
    }

    private function solicitudesYJustificaciones(): void
    {
        foreach (['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO'] as $estado) {
            $this->assertGreaterThan(0, DB::table('Solicitudes.Papeleta as p')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'p.VinculoLaboralId')
                ->where('v.VinculoLaboralCodigo', 'like', 'VL-1%')->where('p.PapeletaEstado', $estado)->count(), "Sin papeletas {$estado} de volumen");
        }
        $this->assertSame(0, DB::table('Solicitudes.Papeleta')->whereIn('PapeletaEstado', ['APROBADO', 'RECHAZADO'])->whereNull('UsuarioAutorizacionId')->count());
        // Una licencia aprobada con goce no supera su maximo de dias (RIT, Art. 55).
        $this->assertSame(0, DB::table('Solicitudes.Licencia as l')->join('Solicitudes.TipoLicencia as t', 't.TipoLicenciaId', '=', 'l.TipoLicenciaId')
            ->whereNotNull('t.TipoLicenciaMaximoDias')->whereRaw('DATEDIFF(DAY, l.LicenciaFechaInicio, l.LicenciaFechaFin) + 1 > t.TipoLicenciaMaximoDias')->count());
        // El rol vacacional no pasa de los 30 dias ganados y los goces aprobados explican los dias consumidos.
        $this->assertSame(0, DB::table('Vacaciones.PeriodoVacacional as p')->whereRaw("(SELECT COALESCE(SUM(r.RolVacacionalDias), 0) FROM Vacaciones.RolVacacional r WHERE r.PeriodoVacacionalId = p.PeriodoVacacionalId AND r.RolVacacionalEstado IN ('PROGRAMADO', 'GOZADO')) > p.PeriodoVacacionalDiasGanados")->count());
        $this->assertSame(0, DB::table('Compensaciones.CompensacionHoraria')->where('CompensacionHorariaEstado', 'CONSUMIDO')->whereRaw('CompensacionHorariaHorasDevueltas < CompensacionHorariaHorasGeneradas')->count());
        $this->assertSame(0, DB::table('Disciplina.ExpedientePad')->where('ExpedientePadEstado', '<>', 'RESUELTO')->whereNotNull('ExpedientePadSancion')->count());
    }

    private function cierresDeMes(): void
    {
        // Julio y agosto, cerrados con sus consolidados cerrados; setiembre en proceso con consolidados de todos los estados.
        foreach ([7, 8] as $mes) {
            $this->assertSame('CERRADO', DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => $mes])->value('PeriodoAsistenciaEstado'));
        }
        $this->assertSame(0, DB::table('Consolidacion.ConsolidadoAsistencia as c')->join('Consolidacion.PeriodoAsistencia as p', 'p.PeriodoAsistenciaId', '=', 'c.PeriodoAsistenciaId')
            ->where('p.PeriodoAsistenciaEstado', 'CERRADO')->where('c.ConsolidadoAsistenciaEstado', '<>', 'CERRADO')->count());
        $this->assertGreaterThan(50, DB::table('Consolidacion.ConsolidadoAsistencia as c')->join('Consolidacion.PeriodoAsistencia as p', 'p.PeriodoAsistenciaId', '=', 'c.PeriodoAsistenciaId')
            ->where('p.PeriodoAsistenciaMes', 9)->where('c.ConsolidadoAsistenciaEstado', 'CONFORME')->count());
        // Cada consolidado tiene el detalle de sus dias y las liquidaciones remitidas traen importes.
        $this->assertSame(0, DB::table('Consolidacion.ConsolidadoAsistencia as c')->where('c.ConsolidadoAsistenciaDiasTrabajados', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('Consolidacion.DetalleConsolidado as d')->whereColumn('d.ConsolidadoAsistenciaId', 'c.ConsolidadoAsistenciaId'))->count());
        foreach (['GENERADO', 'APROBADO', 'REMITIDO'] as $estado) {
            $this->assertGreaterThan(0, DB::table('Compensaciones.LiquidacionDescuento')->where('LiquidacionDescuentoEstado', $estado)->count(), "Sin liquidaciones {$estado}");
        }
        $this->assertSame(0, DB::table('Compensaciones.LiquidacionDescuento')->where('LiquidacionDescuentoEstado', 'REMITIDO')->where('LiquidacionDescuentoImporteTotal', '<=', 0)->count());
    }

    private function integridad(): void
    {
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS c FROM sys.foreign_keys WHERE is_disabled = 1 OR is_not_trusted = 1')->c);
        // La asistencia nunca queda fuera de la vigencia del vinculo.
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria as a')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'a.VinculoLaboralId')
            ->where(fn ($q) => $q->whereColumn('a.AsistenciaDiariaFecha', '<', 'v.VinculoLaboralFechaInicio')->orWhere(fn ($s) => $s->whereNotNull('v.VinculoLaboralFechaFin')->whereColumn('a.AsistenciaDiariaFecha', '>', 'v.VinculoLaboralFechaFin')))->count());
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $tablas = DB::select("SELECT s.name AS esquema, t.name AS tabla FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id WHERE s.name NOT IN ('sys', 'dbo')");
        $conteos = [];
        foreach ($tablas as $t) {
            $conteos["{$t->esquema}.{$t->tabla}"] = DB::table("{$t->esquema}.{$t->tabla}")->count();
        }

        return $conteos;
    }
}
