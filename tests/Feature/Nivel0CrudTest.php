<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Nivel0Modulos;

/**
 * Modulos de Nivel 0 (catalogos y raices). Los casos genericos (listar, crear, validar, duplicados,
 * editar, desactivar) vienen de CrudModulosTestCase; aqui van los casos propios de cada modulo.
 */
class Nivel0CrudTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel0Modulos::all();
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
