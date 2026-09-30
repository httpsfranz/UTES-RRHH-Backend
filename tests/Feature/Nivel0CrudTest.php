<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Nivel0Modulos;
use Tests\TestCase;

/**
 * Prueba de integracion REAL de los modulos de Nivel 0: HTTP -> Laravel -> SQL Server
 * -> Resource -> JSON. Corre contra la base configurada en .env (sqlsrv), no contra
 * SQLite: el esquema vive en SQL Server. DatabaseTransactions revierte cada caso,
 * asi que no deja filas ni consume identidades permanentemente.
 *
 * Requiere la base con el esquema aplicado y sembrada (php artisan migrate --seed).
 */
class Nivel0CrudTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string,array{0:string}>
     */
    public static function modulos(): array
    {
        return array_map(fn (string $slug) => [$slug], array_combine(
            array_keys(Nivel0Modulos::all()),
            array_keys(Nivel0Modulos::all()),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function spec(string $slug): array
    {
        return Nivel0Modulos::all()[$slug];
    }

    private function crear(array $spec, array $override = []): int
    {
        $respuesta = $this->postJson($spec['endpoint'], $override + $spec['create']);
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

        $respuesta = $this->postJson($spec['endpoint'], $spec['create']);

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

        $respuesta = $this->postJson($spec['endpoint'], []);

        $respuesta->assertStatus(422)->assertJsonValidationErrors($spec['required']);
    }

    #[DataProvider('modulos')]
    public function test_texto_mas_largo_que_la_columna_responde_422(string $slug): void
    {
        $spec = $this->spec($slug);

        foreach ($spec['maxlen'] as $campo => $largo) {
            $this->postJson($spec['endpoint'], [$campo => str_repeat('x', $largo + 1)] + $spec['create'])
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

        $this->postJson($spec['endpoint'], $spec['create'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($campos);
    }

    #[DataProvider('modulos')]
    public function test_valores_invalidos_se_rechazan_con_422(string $slug): void
    {
        $spec = $this->spec($slug);

        foreach ($spec['invalid'] as [$override, $campo]) {
            $this->postJson($spec['endpoint'], $override + $spec['create'])
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
        $this->patchJson("{$spec['endpoint']}/{$id}", $spec['patch'] + $spec['create'])->assertOk();
        $this->putJson("{$spec['endpoint']}/{$id}", $spec['patch'] + $spec['create'])->assertOk();

        $this->assertNotNull(DB::table($spec['table'])->where($spec['pk'], $id)->first());
        $this->patchJson("{$spec['endpoint']}/999999999", $spec['patch'])->assertNotFound();
    }

    #[DataProvider('modulos')]
    public function test_actualizar_con_valor_invalido_responde_422(string $slug): void
    {
        $spec = $this->spec($slug);
        $id = $this->crear($spec);

        foreach ($spec['invalid'] as [$override, $campo]) {
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
        if ($spec['estado'] === null) {
            $this->assertNull($fila, 'La tabla no tiene Estado: debe borrarse fisicamente.');
        } else {
            $this->assertNotNull($fila, 'La baja debe ser logica.');
            $this->assertEquals(0, $fila->{$spec['estado']});
            $this->getJson("{$spec['endpoint']}/{$id}")->assertOk()->assertJsonPath('data.activo', false);
        }
    }

    public function test_filtros_buscar_y_estado_del_listado(): void
    {
        // Prefijo propio (ZZQ) para que los datos de prueba del seeder no interfieran con los conteos.
        $this->postJson('/api/microredes', ['MicroredCodigo' => 'ZZQ-BUS', 'MicroredNombre' => 'Microred Buscable ZZQ'])->assertCreated();
        $inactiva = $this->postJson('/api/microredes', ['MicroredCodigo' => 'ZZQ-INA', 'MicroredNombre' => 'Microred Inactiva ZZQ'])->json('data.id');
        $this->deleteJson("/api/microredes/{$inactiva}")->assertOk();

        $this->getJson('/api/microredes?buscar=Buscable ZZQ')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/microredes?buscar=ZZQ-BUS')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/microredes?buscar=ZZQ&estado=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/microredes?buscar=ZZQ&estado=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/microredes?por_pagina=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/microredes?por_pagina=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_buscar_en_calendario_y_periodos_funciona(): void
    {
        $this->crear($this->spec('periodos-asistencia'));
        $this->crear($this->spec('calendario-no-laborable'), ['CalendarioNoLaborableDescripcion' => 'Fiestas Patrias ZZ']);

        $this->getJson('/api/periodos-asistencia?buscar=2031-07')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/periodos-asistencia?buscar=2031-08')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/periodos-asistencia?buscar=abierto')->assertOk()->assertJsonPath('data.0.estado', 'ABIERTO');

        $this->getJson('/api/calendario-no-laborable?buscar=Patrias ZZ')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/calendario-no-laborable?buscar=2031-07-28')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/calendario-no-laborable?buscar=no-existe-zz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calendario_acepta_microred_y_rechaza_duplicado_por_microred(): void
    {
        $microred = $this->postJson('/api/microredes', ['MicroredCodigo' => 'ZZ-CAL', 'MicroredNombre' => 'Microred Calendario ZZ'])->json('data.id');
        $payload = ['CalendarioNoLaborableFecha' => '2031-12-25', 'CalendarioNoLaborableTipo' => 'FERIADO'];

        $this->postJson('/api/calendario-no-laborable', $payload + ['MicroredId' => $microred])
            ->assertCreated()->assertJsonPath('data.microred_id', $microred);
        // Misma fecha para toda la Red (NULL) y para una microred: son filas distintas.
        $this->postJson('/api/calendario-no-laborable', $payload)->assertCreated()->assertJsonPath('data.microred_id', null);
        $this->postJson('/api/calendario-no-laborable', $payload + ['MicroredId' => $microred])->assertStatus(422);
        $this->postJson('/api/calendario-no-laborable', $payload)->assertStatus(422);
    }

    public function test_periodo_no_se_puede_repetir_ni_invertir_fechas_al_editar(): void
    {
        $spec = $this->spec('periodos-asistencia');
        $id = $this->crear($spec);

        $this->patchJson("/api/periodos-asistencia/{$id}", ['PeriodoAsistenciaFechaFin' => '2031-06-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['PeriodoAsistenciaFechaFin']);
    }

    public function test_dispositivo_se_relaciona_con_un_establecimiento_existente(): void
    {
        $spec = $this->spec('dispositivos-marcacion');
        $eess = (int) DB::table('Organizacion.EstablecimientoSalud')->value('EessId');
        $this->assertGreaterThan(0, $eess, 'Falta sembrar EstablecimientoSalud (php artisan migrate --seed).');

        $this->postJson($spec['endpoint'], ['EessId' => $eess] + $spec['create'])
            ->assertCreated()
            ->assertJsonPath('data.eess_id', $eess)
            ->assertJsonPath('data.eess.id', $eess);
    }

    public function test_tipo_colegiatura_expone_su_profesion(): void
    {
        $spec = $this->spec('tipos-colegiatura');
        $profesion = (int) DB::table('Personal.Profesion')->value('ProfesionId');

        $this->postJson($spec['endpoint'], ['ProfesionId' => $profesion] + $spec['create'])
            ->assertCreated()
            ->assertJsonPath('data.profesion.id', $profesion);
    }

    public function test_solo_lectura_de_auditoria_y_logs_de_integracion(): void
    {
        foreach (['/api/auditoria', '/api/logs-integracion'] as $ruta) {
            $this->getJson($ruta)->assertOk()->assertJsonStructure(['data', 'meta']);
            $this->postJson($ruta, [])->assertStatus(405);
            $this->deleteJson("{$ruta}/1")->assertStatus(405);
            $this->getJson("{$ruta}/999999999")->assertNotFound();
        }
    }

    public function test_un_delete_fisico_sobre_una_fila_referenciada_devuelve_409_en_espanol(): void
    {
        // Ninguna ruta de Nivel 0 hace DELETE fisico sobre una fila referenciada (todas dan de baja),
        // asi que se fuerza la violacion de FK con una ruta temporal para probar el manejador global.
        Route::delete('/api/_prueba/eess/{id}', fn (int $id) => DB::table('Organizacion.EstablecimientoSalud')->where('EessId', $id)->delete());

        $eess = (int) DB::table('Organizacion.EstablecimientoSalud')->value('EessId');
        $this->postJson('/api/dispositivos-marcacion', [
            'EessId' => $eess, 'DispositivoMarcacionCodigo' => 'ZZ-FK', 'DispositivoMarcacionNombre' => 'Lector FK', 'DispositivoMarcacionTipo' => 'Facial',
        ])->assertCreated();

        $this->deleteJson("/api/_prueba/eess/{$eess}")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'No se puede eliminar: el registro está siendo usado por otros datos. Desactívalo en su lugar.']);
    }

    public function test_desactivar_una_microred_con_dependientes_sigue_permitido(): void
    {
        $microred = $this->postJson('/api/microredes', ['MicroredCodigo' => 'ZZ-FK2', 'MicroredNombre' => 'Microred FK2 ZZ'])->json('data.id');
        $this->postJson('/api/calendario-no-laborable', [
            'MicroredId' => $microred, 'CalendarioNoLaborableFecha' => '2031-05-01', 'CalendarioNoLaborableTipo' => 'FERIADO',
        ])->assertCreated();

        $this->deleteJson("/api/microredes/{$microred}")->assertOk();
    }

    public function test_un_404_y_un_422_siempre_son_json(): void
    {
        $this->getJson('/api/microredes/999999999')->assertNotFound()->assertExactJson(['message' => 'El registro solicitado no existe.']);
        $this->getJson('/api/ruta-que-no-existe')->assertNotFound()->assertExactJson(['message' => 'La ruta solicitada no existe.']);
        $this->postJson('/api/microredes', [])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }
}
