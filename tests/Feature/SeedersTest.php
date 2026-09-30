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
