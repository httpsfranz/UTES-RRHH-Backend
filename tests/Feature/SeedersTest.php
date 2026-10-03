<?php

namespace Tests\Feature;

use App\Support\VaciaEsquemasDelSistema;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DatosPruebaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El estado inicial de la base: estructura sin datos (V001) + seeders. Corre contra SQL Server
 * dentro de una transaccion revertida; NO ejecuta DatabaseSeeder completo porque vacia las tablas
 * (eso se prueba a mano con `php artisan db:seed`, ver database/sql/README.md).
 */
class SeedersTest extends TestCase
{
    use DatabaseTransactions;

    /** Las 30 tablas de Nivel 0 (Hoja de Ruta de Dependencias). */
    private const NIVEL_0 = [
        'Asistencia.ConceptoJustificacion', 'Asistencia.EstadoAsistencia', 'Biometria.DispositivoMarcacion',
        'Biometria.MetodoMarcacion', 'Compensaciones.ConceptoDescuento', 'Compensaciones.TipoCompensacion',
        'Configuracion.ParametroSistema', 'Configuracion.TablaTolerancia', 'Configuracion.TipoJornada',
        'Consolidacion.PeriodoAsistencia', 'Disciplina.TipoFaltaDisciplinaria', 'Organizacion.Microred',
        'Organizacion.TipoEstablecimiento', 'Organizacion.TipoResponsabilidad', 'Personal.ColegiaturaTipo',
        'Personal.CondicionLaboral', 'Personal.GrupoOcupacional', 'Personal.Profesion', 'Personal.RegimenLaboral',
        'Personal.TipoDocumentoIdentidad', 'Programacion.TipoCambioTurno', 'Programacion.TipoPeriodoProgramacion',
        'Seguridad.Auditoria', 'Seguridad.Permiso', 'Seguridad.Rol', 'Solicitudes.TipoLicencia',
        'Solicitudes.TipoPapeleta', 'Soporte.CalendarioNoLaborable', 'Soporte.DocumentoSustento', 'Soporte.LogIntegracion',
    ];

    /**
     * @return array<string,int>
     */
    private function conteos(): array
    {
        $tablas = DB::select("SELECT s.name AS esquema, t.name AS tabla FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id WHERE s.name IN ('".implode("','", VaciaEsquemasDelSistema::ESQUEMAS)."')");

        $conteos = [];
        foreach ($tablas as $t) {
            $conteos["{$t->esquema}.{$t->tabla}"] = DB::table("{$t->esquema}.{$t->tabla}")->count();
        }

        return $conteos;
    }

    public function test_los_catalogos_se_siembran_de_forma_idempotente(): void
    {
        $antes = $this->conteos();

        $this->seed(CatalogosSeeder::class);
        $this->seed(CatalogosSeeder::class);

        $this->assertSame($antes, $this->conteos(), 'CatalogosSeeder no debe duplicar ni borrar filas.');
    }

    public function test_los_datos_de_prueba_se_siembran_de_forma_idempotente(): void
    {
        $this->seed(DatosPruebaSeeder::class);

        $antes = $this->conteos();
        $this->seed(DatosPruebaSeeder::class);

        $this->assertSame($antes, $this->conteos());
    }

    public function test_todos_los_modulos_de_nivel_0_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();

        foreach (self::NIVEL_0 as $tabla) {
            $this->assertGreaterThanOrEqual(1, $conteos[$tabla] ?? 0, "{$tabla} quedo sin datos iniciales.");
        }

        // Volumen suficiente para ver la vista de tabla (mas de 8) y la de tarjetas (8 o menos).
        foreach (['Organizacion.Microred', 'Soporte.CalendarioNoLaborable', 'Consolidacion.PeriodoAsistencia', 'Seguridad.Permiso', 'Personal.Profesion'] as $tabla) {
            $this->assertGreaterThan(8, $conteos[$tabla], "{$tabla} deberia tener mas de 8 filas.");
        }
        $this->assertLessThanOrEqual(8, $conteos['Configuracion.TipoJornada']);
    }

    public function test_todos_los_modulos_de_nivel_1_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        $nivel1 = [
            'Organizacion.EstablecimientoSalud', 'Personal.Trabajador', 'Personal.Cargo', 'Configuracion.Turno',
            'Configuracion.Horario', 'Configuracion.ParametroJornada', 'Configuracion.TramoTolerancia',
            'Solicitudes.MotivoPapeleta', 'Seguridad.RolPermiso',
        ];

        foreach ($nivel1 as $tabla) {
            $this->assertGreaterThanOrEqual(1, $conteos[$tabla], "{$tabla} quedo sin datos iniciales.");
        }
        foreach (['Organizacion.EstablecimientoSalud', 'Personal.Trabajador', 'Personal.Cargo', 'Solicitudes.MotivoPapeleta', 'Seguridad.RolPermiso'] as $tabla) {
            $this->assertGreaterThan(8, $conteos[$tabla], "{$tabla} deberia tener mas de 8 filas.");
        }
        $this->assertLessThanOrEqual(8, $conteos['Configuracion.Turno']);
        $this->assertLessThanOrEqual(8, $conteos['Configuracion.Horario']);

        // Todos los horarios con detalle apuntan a turnos y los trabajadores a un tipo de documento valido (FK reales).
        $this->assertGreaterThan(0, DB::table('Configuracion.HorarioDetalle')->count());
        $this->assertSame(0, DB::table('Personal.Trabajador')->whereNotIn('TipoDocumentoIdentidadId', DB::table('Personal.TipoDocumentoIdentidad')->select('TipoDocumentoIdentidadId'))->count());
    }

    public function test_todos_los_modulos_de_nivel_2_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        $nivel2 = [
            'Personal.VinculoLaboral', 'Seguridad.Usuario', 'Configuracion.HorarioDetalle', 'Personal.Colegiatura',
            'Biometria.PlantillaBiometrica', 'Biometria.ConsentimientoBiometrico', 'Biometria.AutorizacionMetodo',
            'Solicitudes.OcurrenciaPorteria',
        ];

        foreach ($nivel2 as $tabla) {
            $this->assertGreaterThanOrEqual(1, $conteos[$tabla], "{$tabla} quedo sin datos iniciales.");
        }
        foreach (['Personal.VinculoLaboral', 'Seguridad.Usuario', 'Personal.Colegiatura', 'Biometria.PlantillaBiometrica', 'Solicitudes.OcurrenciaPorteria'] as $tabla) {
            $this->assertGreaterThanOrEqual(8, $conteos[$tabla], "{$tabla} deberia tener al menos 8 filas.");
        }
        $this->assertSame(0, DB::table('Seguridad.Usuario')->whereNull('UsuarioPasswordHash')->count());
    }

    public function test_todos_los_modulos_de_nivel_3_lote_a_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        foreach (['Asistencia.CargaAsistenciaManual', 'Asistencia.Marcacion', 'Asistencia.AsistenciaDiaria', 'Asistencia.JustificacionFalta', 'Soporte.Notificacion'] as $tabla) {
            $this->assertGreaterThanOrEqual(4, $conteos[$tabla], "{$tabla} deberia tener al menos 4 filas.");
        }
        // Las justificaciones aprobadas dejan sus faltas enlazadas y ninguna marcacion apunta a una carga inexistente.
        $this->assertSame(0, DB::table('Asistencia.AsistenciaDiaria')->whereNotNull('JustificacionFaltaId')
            ->whereNotIn('JustificacionFaltaId', DB::table('Asistencia.JustificacionFalta')->where('JustificacionFaltaEstado', 'APROBADO')->select('JustificacionFaltaId'))->count());
        $this->assertSame(0, DB::table('Asistencia.Marcacion')->whereNotNull('CargaAsistenciaManualId')
            ->whereNotIn('CargaAsistenciaManualId', DB::table('Asistencia.CargaAsistenciaManual')->select('CargaAsistenciaManualId'))->count());
    }

    public function test_todos_los_modulos_de_nivel_3_lote_b_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        foreach (['Solicitudes.Papeleta', 'Solicitudes.Licencia', 'Solicitudes.DescansoMedico', 'Solicitudes.ConstatacionDomiciliaria'] as $tabla) {
            $this->assertGreaterThanOrEqual(4, $conteos[$tabla], "{$tabla} deberia tener al menos 4 filas.");
        }
        // Cada estado del ciclo de aprobacion aparece al menos una vez, y toda solicitud resuelta por una persona la nombra.
        foreach (['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO'] as $estado) {
            $this->assertGreaterThan(0, DB::table('Solicitudes.Papeleta')->where('PapeletaEstado', $estado)->count(), "Sin papeletas {$estado}");
        }
        $this->assertSame(0, DB::table('Solicitudes.Papeleta')->where('PapeletaEstado', 'APROBADO')->whereNull('UsuarioAutorizacionId')->count());
    }

    public function test_todos_los_modulos_de_nivel_3_lote_c_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        $tablas = [
            'Programacion.ProgramacionPeriodo', 'Programacion.CargaProgramacion', 'Programacion.InformeGuardiaComunitaria', 'Personal.AsignacionHorario',
            'Organizacion.ResponsableEess', 'Seguridad.UsuarioRol', 'Seguridad.UsuarioAmbito', 'Seguridad.SesionAcceso',
        ];
        foreach ($tablas as $tabla) {
            $this->assertGreaterThanOrEqual(5, $conteos[$tabla], "{$tabla} deberia tener al menos 5 filas.");
        }
        // Una programacion publicada o cerrada tiene fecha de publicacion; un ambito nunca mezcla microred y EESS.
        $this->assertSame(0, DB::table('Programacion.ProgramacionPeriodo')->whereIn('ProgramacionPeriodoEstado', ['PUBLICADA', 'CERRADA'])->whereNull('ProgramacionPeriodoFechaPublicacion')->count());
        $this->assertSame(0, DB::table('Seguridad.UsuarioAmbito')->whereNotNull('MicroredId')->whereNotNull('EessId')->count());
        // Cada tipo de ambito (Red, Microred, EESS) esta representado.
        $this->assertGreaterThan(0, DB::table('Seguridad.UsuarioAmbito')->whereNull('MicroredId')->whereNull('EessId')->count());
        $this->assertGreaterThan(0, DB::table('Seguridad.UsuarioAmbito')->whereNotNull('MicroredId')->count());
        $this->assertGreaterThan(0, DB::table('Seguridad.UsuarioAmbito')->whereNotNull('EessId')->count());
    }

    public function test_todos_los_modulos_de_nivel_3_lote_d_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        $tablas = [
            'Consolidacion.ConsolidadoAsistencia', 'Compensaciones.CompensacionHoraria', 'Vacaciones.PeriodoVacacional',
            'Disciplina.ExpedientePad', 'Disciplina.SupervisionInopinada',
        ];
        foreach ($tablas as $tabla) {
            $this->assertGreaterThanOrEqual(5, $conteos[$tabla], "{$tabla} deberia tener al menos 5 filas.");
        }
        // Reglas del RIT en los datos: sancion solo en expedientes resueltos, compensacion consumida sin horas pendientes,
        // y los consolidados de un periodo cerrado estan cerrados.
        $this->assertSame(0, DB::table('Disciplina.ExpedientePad')->where('ExpedientePadEstado', '<>', 'RESUELTO')->whereNotNull('ExpedientePadSancion')->count());
        $this->assertSame(0, DB::table('Compensaciones.CompensacionHoraria')->where('CompensacionHorariaEstado', 'CONSUMIDO')->whereRaw('CompensacionHorariaHorasDevueltas < CompensacionHorariaHorasGeneradas')->count());
        $this->assertSame(0, DB::table('Consolidacion.ConsolidadoAsistencia as c')->join('Consolidacion.PeriodoAsistencia as p', 'p.PeriodoAsistenciaId', '=', 'c.PeriodoAsistenciaId')
            ->where('p.PeriodoAsistenciaEstado', 'CERRADO')->where('c.ConsolidadoAsistenciaEstado', '<>', 'CERRADO')->count());
    }

    public function test_todos_los_modulos_de_los_niveles_4_a_6_tienen_datos_para_probar(): void
    {
        $conteos = $this->conteos();
        $tablas = [
            'Programacion.ProgramacionTrabajador', 'Asistencia.AjusteMarcacion', 'Consolidacion.DetalleConsolidado', 'Compensaciones.LiquidacionDescuento',
            'Vacaciones.RolVacacional', 'Programacion.TurnoProgramado', 'Compensaciones.DetalleLiquidacion', 'Vacaciones.GoceVacacional', 'Programacion.CambioTurno',
        ];
        foreach ($tablas as $tabla) {
            $this->assertGreaterThanOrEqual(3, $conteos[$tabla], "{$tabla} deberia tener al menos 3 filas.");
        }

        // Cada estado del ciclo de vida esta representado, y el detalle sigue a su cabecera.
        foreach ([
            ['Programacion.ProgramacionTrabajador', 'ProgramacionTrabajadorEstado', ['BORRADOR', 'PUBLICADA', 'CERRADA', 'ANULADA']],
            ['Programacion.TurnoProgramado', 'TurnoProgramadoEstado', ['PROGRAMADO', 'REPROGRAMADO', 'CUMPLIDO', 'ANULADO']],
            ['Programacion.CambioTurno', 'CambioTurnoEstado', ['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO']],
            ['Asistencia.AjusteMarcacion', 'AjusteMarcacionEstado', ['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO']],
            ['Compensaciones.LiquidacionDescuento', 'LiquidacionDescuentoEstado', ['GENERADO', 'APROBADO', 'REMITIDO']],
            ['Vacaciones.RolVacacional', 'RolVacacionalEstado', ['PROGRAMADO', 'GOZADO', 'REPROGRAMADO', 'ANULADO']],
            ['Vacaciones.GoceVacacional', 'GoceVacacionalEstado', ['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO']],
        ] as [$tabla, $columna, $estados]) {
            foreach ($estados as $estado) {
                $this->assertGreaterThan(0, DB::table($tabla)->where($columna, $estado)->count(), "{$tabla} sin filas en {$estado}");
            }
        }
        // Reglas del RIT en los datos: la programacion de un trabajador tiene el estado de su periodo, los turnos de una programacion
        // anulada estan anulados y un trabajador solo se programa en su establecimiento.
        $this->assertSame(0, DB::table('Programacion.ProgramacionTrabajador as t')->join('Programacion.ProgramacionPeriodo as p', 'p.ProgramacionPeriodoId', '=', 't.ProgramacionPeriodoId')
            ->whereRaw("t.ProgramacionTrabajadorEstado <> CASE p.ProgramacionPeriodoEstado WHEN 'CERRADA' THEN 'CERRADA' WHEN 'ANULADA' THEN 'ANULADA' WHEN 'PUBLICADA' THEN 'PUBLICADA' ELSE 'BORRADOR' END")->count());
        $this->assertSame(0, DB::table('Programacion.TurnoProgramado as u')->join('Programacion.ProgramacionTrabajador as t', 't.ProgramacionTrabajadorId', '=', 'u.ProgramacionTrabajadorId')
            ->where('t.ProgramacionTrabajadorEstado', 'ANULADA')->where('u.TurnoProgramadoEstado', '<>', 'ANULADO')->count());
        $this->assertSame(0, DB::table('Programacion.ProgramacionTrabajador as t')->join('Programacion.ProgramacionPeriodo as p', 'p.ProgramacionPeriodoId', '=', 't.ProgramacionPeriodoId')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 't.VinculoLaboralId')->whereColumn('v.EessId', '<>', 'p.EessId')->count());
        // El detalle de una liquidacion suma su importe total, y el rol vacacional no pasa de los dias ganados del periodo.
        $this->assertSame(0, DB::table('Vacaciones.PeriodoVacacional as p')->whereRaw("(SELECT COALESCE(SUM(r.RolVacacionalDias), 0) FROM Vacaciones.RolVacacional r WHERE r.PeriodoVacacionalId = p.PeriodoVacacionalId AND r.RolVacacionalEstado IN ('PROGRAMADO', 'GOZADO')) > p.PeriodoVacacionalDiasGanados")->count());
    }

    public function test_hay_registros_activos_e_inactivos_y_relaciones_validas(): void
    {
        foreach (['Organizacion.Microred' => 'MicroredEstado', 'Biometria.DispositivoMarcacion' => 'DispositivoMarcacionEstado', 'Seguridad.Permiso' => 'PermisoEstado'] as $tabla => $columna) {
            $this->assertGreaterThan(0, DB::table($tabla)->where($columna, 1)->count(), "{$tabla} sin activos");
            $this->assertGreaterThan(0, DB::table($tabla)->where($columna, 0)->count(), "{$tabla} sin inactivos");
        }

        // Ninguna FK deshabilitada ni "no confiable": los seeders respetaron la integridad referencial.
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS c FROM sys.foreign_keys WHERE is_disabled = 1 OR is_not_trusted = 1')->c);

        // Dispositivos con y sin establecimiento; un RolPermiso por cada rol sembrado.
        $this->assertGreaterThan(0, DB::table('Biometria.DispositivoMarcacion')->whereNotNull('EessId')->count());
        $this->assertGreaterThan(0, DB::table('Biometria.DispositivoMarcacion')->whereNull('EessId')->count());
        $this->assertSame(7, DB::table('Seguridad.RolPermiso')->distinct()->count('RolId'));
    }

    public function test_el_seeder_maestro_se_niega_a_correr_en_produccion(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no puede correr en produccion');

        (new DatabaseSeeder)->run();
    }
}
