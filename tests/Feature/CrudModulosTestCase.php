<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prueba de integracion REAL de modulos CRUD: HTTP -> Laravel -> SQL Server -> Resource -> JSON.
 * Corre contra la base configurada en .env (sqlsrv), no contra SQLite: el esquema vive en SQL Server.
 * DatabaseTransactions revierte cada caso, asi que no deja filas.
 *
 * Cada nivel de la Hoja de Ruta (Nivel0CrudTest, Nivel1CrudTest...) aporta su especificacion, que espeja
 * el DDL de database/sql: que columnas son obligatorias, cuales son UNIQUE y el largo maximo de cada texto.
 * Requiere la base con el esquema aplicado y sembrada (php artisan migrate:fresh --seed).
 *
 * Claves de cada especificacion:
 *   endpoint  ruta base                      table/pk/estado   tabla, PK y columna BIT (null = DELETE fisico, false = sin DELETE)
 *   create    payload valido (PascalCase)    keys              claves que devuelve el Resource
 *   fk        columna FK => [tabla, pk, filtro] (fila sembrada) o fn (CrudModulosTestCase $t): int (la crea el test)
 *   anula     true = DELETE no desactiva un BIT sino que pasa el estado a ANULADO (ocurrencias)
 *   required  columnas NOT NULL              unique            columnas UNIQUE
 *   maxlen    largo maximo por columna       patch/patchKey    cambio parcial y clave del Resource que debe reflejarlo
 *   uniqueComposite  columna que reporta el error cuando el UNIQUE es compuesto (opcional)
 *   invalid   lista de [payload que pisa a create, columna que debe fallar]
 */
abstract class CrudModulosTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string,int> ids de FK ya resueltos en este test (una FK dinamica se crea una sola vez por test). */
    private array $fkResueltas = [];

    /**
     * @return array<string,array<string,mixed>>
     */
    abstract protected static function especificaciones(): array;

    /**
     * @return array<string,array{0:string}>
     */
    public static function modulos(): array
    {
        $slugs = array_keys(static::especificaciones());

        return array_map(fn (string $slug) => [$slug], array_combine($slugs, $slugs));
    }

    /**
     * @return array<string,mixed>
     */
    protected function spec(string $slug): array
    {
        return static::especificaciones()[$slug];
    }

    /**
     * Payload valido del modulo: lo declarado en `create` mas los ids de las filas sembradas de las que depende.
     *
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    protected function payload(array $spec, array $override = []): array
    {
        $ids = [];
        foreach ($spec['fk'] ?? [] as $columna => $definicion) {
            $ids[$columna] = $this->fkResueltas[$columna] ??= $definicion instanceof Closure
                ? (int) $definicion($this)
                : $this->idSembrado(...$definicion);
        }

        return $override + $spec['create'] + $ids;
    }

    private function idSembrado(string $tabla, string $pk, array $filtro): int
    {
        $id = DB::table($tabla)->where($filtro)->value($pk);
        $this->assertNotNull($id, "Falta la fila sembrada {$tabla} ".json_encode($filtro).' (php artisan migrate:fresh --seed).');

        return (int) $id;
    }

    /**
     * Crea un trabajador activo SIN vinculo, usuario, colegiatura ni biometria: sirve de base para probar los
     * modulos de Nivel 2 sin chocar con los trabajadores sembrados.
     *
     * @param  array<string,mixed>  $extra  columnas de Personal.Trabajador a pisar (p. ej. ProfesionId)
     */
    public function nuevoTrabajador(array $extra = []): int
    {
        return (int) DB::table('Personal.Trabajador')->insertGetId($extra + [
            'TipoDocumentoIdentidadId' => $this->idSembrado('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'DNI']),
            'TrabajadorNumeroDocumento' => (string) random_int(71000000, 78999999),
            'TrabajadorNombres' => 'Prueba',
            'TrabajadorApellidoPaterno' => 'Nivel',
            'TrabajadorApellidoMaterno' => 'Dos',
            'TrabajadorEstado' => 1,
        ], 'TrabajadorId');
    }

    /** Registra un consentimiento biometrico del trabajador (aceptado o revocado) directamente en la base. */
    public function conConsentimiento(int $trabajadorId, bool $acepta = true): int
    {
        DB::table('Biometria.ConsentimientoBiometrico')->insert([
            'TrabajadorId' => $trabajadorId,
            'ConsentimientoBiometricoAceptado' => $acepta ? 1 : 0,
            'ConsentimientoBiometricoVersion' => 'v1.0',
        ]);

        return $trabajadorId;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $override
     */
    protected function crear(array $spec, array $override = []): int
    {
        $respuesta = $this->postJson($spec['endpoint'], $this->payload($spec, $override));
        $respuesta->assertCreated();

        return (int) $respuesta->json('data.id');
    }

    #[DataProvider('modulos')]
    public function test_listado_paginado_con_estructura_del_resource(string $slug): void
    {
        $spec = $this->spec($slug);

        $this->crear($spec);

        $respuesta = $this->getJson($spec['endpoint'].'?por_pagina=100');

        $respuesta->assertOk()
            ->assertJsonStructure(['data' => [['id']], 'links', 'meta' => ['current_page', 'per_page', 'total']]);
        $this->assertGreaterThanOrEqual(1, $respuesta->json('meta.total'));
        $this->assertLessThanOrEqual(100, $respuesta->json('meta.per_page'));
    }

    #[DataProvider('modulos')]
    public function test_crear_devuelve_201_con_las_claves_acordadas_y_persiste(string $slug): void
    {
        $spec = $this->spec($slug);

        $respuesta = $this->postJson($spec['endpoint'], $this->payload($spec));

        $respuesta->assertCreated();
        $this->assertEqualsCanonicalizing($spec['keys'], array_keys($respuesta->json('data')), "Claves del Resource de {$slug}");
        $this->assertIsInt($respuesta->json('data.id'));

        $fila = DB::table($spec['table'])->where($spec['pk'], $respuesta->json('data.id'))->first();
        $this->assertNotNull($fila, 'La fila no quedo en SQL Server.');

        if ($spec['estado']) {
            $this->assertTrue($respuesta->json('data.activo'), 'activo debe ser booleano true por defecto.');
        }
    }

    #[DataProvider('modulos')]
    public function test_crear_sin_campos_obligatorios_responde_422_por_campo(string $slug): void
    {
        $spec = $this->spec($slug);

        $this->postJson($spec['endpoint'], [])->assertStatus(422)->assertJsonValidationErrors($spec['required']);
    }

    #[DataProvider('modulos')]
    public function test_texto_mas_largo_que_la_columna_responde_422(string $slug): void
    {
        $spec = $this->spec($slug);

        foreach ($spec['maxlen'] as $campo => $largo) {
            $this->postJson($spec['endpoint'], $this->payload($spec, [$campo => str_repeat('x', $largo + 1)]))
                ->assertStatus(422)
                ->assertJsonValidationErrors([$campo]);
        }
        $this->assertTrue(true);
    }

    #[DataProvider('modulos')]
    public function test_duplicados_responden_422_y_no_500(string $slug): void
    {
        $spec = $this->spec($slug);
        $campos = $spec['unique'] ?: array_filter([$spec['uniqueComposite'] ?? null]);
        if ($campos === []) {
            $this->markTestSkipped('Sin restriccion UNIQUE.');
        }

        $this->crear($spec);

        $this->postJson($spec['endpoint'], $this->payload($spec))
            ->assertStatus(422)
            ->assertJsonValidationErrors($campos);
    }

    #[DataProvider('modulos')]
    public function test_valores_invalidos_se_rechazan_con_422(string $slug): void
    {
        $spec = $this->spec($slug);

        // Algunos casos (superposicion de vigencias, duplicados) necesitan el registro base ya guardado.
        if ($spec['seedInvalid'] ?? false) {
            $this->crear($spec);
        }

        foreach ($spec['invalid'] as [$override, $campo]) {
            $this->postJson($spec['endpoint'], $this->payload($spec, $override))
                ->assertStatus(422, 'Se esperaba 422 para '.json_encode($override).' en '.$slug)
                ->assertJsonValidationErrors([$campo]);
        }
        $this->assertTrue(true);
    }

    #[DataProvider('modulos')]
    public function test_mostrar_devuelve_200_y_404_si_no_existe(string $slug): void
    {
        $spec = $this->spec($slug);
        $id = $this->crear($spec);

        $this->getJson("{$spec['endpoint']}/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->getJson("{$spec['endpoint']}/999999999")->assertNotFound();
    }

    #[DataProvider('modulos')]
    public function test_actualizar_parcial_y_completo_sin_chocar_consigo_mismo(string $slug): void
    {
        $spec = $this->spec($slug);
        $id = $this->crear($spec);
        $clave = $spec['patchKey'];
        $campo = array_key_first($spec['patch']);

        $this->patchJson("{$spec['endpoint']}/{$id}", $spec['patch'])
            ->assertOk()
            ->assertJsonPath("data.{$clave}", $spec['patch'][$campo]);

        // El formulario del frontend reenvia el registro completo: el UNIQUE no debe chocar con la propia fila.
        $completo = $this->payload($spec, $spec['patch']);
        $this->patchJson("{$spec['endpoint']}/{$id}", $completo)->assertOk();
        $this->putJson("{$spec['endpoint']}/{$id}", $completo)->assertOk();

        $this->assertNotNull(DB::table($spec['table'])->where($spec['pk'], $id)->first());
        $this->patchJson("{$spec['endpoint']}/999999999", $spec['patch'])->assertNotFound();
    }

    #[DataProvider('modulos')]
    public function test_actualizar_con_valor_invalido_responde_422(string $slug): void
    {
        $spec = $this->spec($slug);
        $id = $this->crear($spec);

        foreach ($spec['invalid'] as [$override, $campo]) {
            // Solo se reenvian los campos del caso invalido, como un PATCH parcial.
            $this->patchJson("{$spec['endpoint']}/{$id}", $override)
                ->assertStatus(422, 'PATCH '.json_encode($override).' en '.$slug)
                ->assertJsonValidationErrors([$campo]);
        }
        $this->assertTrue(true);
    }

    #[DataProvider('modulos')]
    public function test_eliminar_desactiva_o_borra_segun_el_modulo(string $slug): void
    {
        $spec = $this->spec($slug);
        $id = $this->crear($spec);

        if ($spec['estado'] === false) {
            $this->deleteJson("{$spec['endpoint']}/{$id}")->assertStatus(405);

            return;
        }

        $this->deleteJson("{$spec['endpoint']}/{$id}")->assertOk()->assertJsonStructure(['mensaje']);

        $fila = DB::table($spec['table'])->where($spec['pk'], $id)->first();
        if ($spec['anula'] ?? false) {
            $this->assertSame('ANULADO', $fila->{$spec['estado']});
            $this->getJson("{$spec['endpoint']}/{$id}")->assertOk()->assertJsonPath('data.activo', false);
        } elseif ($spec['estado'] === null) {
            $this->assertNull($fila, 'La tabla se elimina fisicamente.');
        } else {
            $this->assertNotNull($fila, 'La baja debe ser logica.');
            $this->assertEquals(0, $fila->{$spec['estado']});
            $this->getJson("{$spec['endpoint']}/{$id}")->assertOk()->assertJsonPath('data.activo', false);
        }
    }
}
