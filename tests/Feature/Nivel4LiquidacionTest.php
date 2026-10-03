<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel4Modulos;

/**
 * Liquidacion de descuentos por inasistencias y tardanzas (RIT, Art. 25): la cabecera (Nivel 4), que se genera desde un
 * consolidado y se aprueba, remite o anula, y sus lineas (Nivel 5), que se completan con el importe de planilla.
 */
class Nivel4LiquidacionTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel4Modulos::liquidacion();
    }

    private function concepto(string $codigo): int
    {
        return $this->idPorCodigo('Compensaciones.ConceptoDescuento', 'ConceptoDescuentoId', 'ConceptoDescuentoCodigo', $codigo);
    }

    // ================================================================== Generar la liquidacion

    public function test_generar_crea_las_lineas_desde_el_consolidado_con_importe_cero(): void
    {
        $consolidado = $this->consolidadoLiquidable();   // 2 dias de falta y 90 minutos de tardanza

        $r = $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $consolidado])
            ->assertCreated()->assertJsonPath('data.estado', 'GENERADO')->assertJsonPath('data.lineas', 2)->assertJsonPath('data.consolidado.estado', 'CONFORME');
        $this->assertEquals(0, $r->json('data.importe_total'));

        $lineas = collect($r->json('data.detalles'))->keyBy('concepto.codigo');
        $this->assertEquals(2, $lineas['DESC_FALTA']['cantidad']);       // dias de falta
        $this->assertEquals(1.5, $lineas['DESC_TARDANZA']['cantidad']);   // las tardanzas se acumulan en horas (RIT, Art. 25)
        $this->assertEquals(0, $lineas['DESC_FALTA']['importe']);

        // Se ve igual en el detalle y en el listado.
        $id = $r->json('data.id');
        $this->getJson("/api/liquidaciones-descuento/{$id}")->assertOk()->assertJsonCount(2, 'data.detalles');
        $this->getJson("/api/liquidaciones-descuento?consolidado_asistencia_id={$consolidado}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lineas', 2);
    }

    public function test_solo_se_liquida_un_consolidado_conforme_o_cerrado_que_tenga_algo_que_descontar(): void
    {
        $vinculo = $this->nuevoVinculo();
        $generado = $this->consolidado($vinculo, 10, ['ConsolidadoAsistenciaDiasFalta' => 1]);
        $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $generado])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
        DB::table('Consolidacion.ConsolidadoAsistencia')->where('ConsolidadoAsistenciaId', $generado)->update(['ConsolidadoAsistenciaEstado' => 'OBSERVADO']);
        $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $generado])->assertStatus(422);

        // Conforme pero sin faltas ni tardanzas: no hay nada que descontar.
        $sinNada = $this->consolidado($this->nuevoVinculo(), 10, ['ConsolidadoAsistenciaEstado' => 'CONFORME', 'ConsolidadoAsistenciaDiasFaltaJustificada' => 2]);
        $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $sinNada])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);

        $this->postJson('/api/liquidaciones-descuento', [])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
        $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => 999999])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
    }

    public function test_un_consolidado_tiene_una_sola_liquidacion_vigente_y_una_anulada_se_regenera(): void
    {
        $consolidado = $this->consolidadoLiquidable();
        $id = $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $consolidado])->assertCreated()->json('data.id');
        $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $consolidado])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);

        // Con la liquidacion vigente, el consolidado no se modifica ni cambia de estado.
        $this->patchJson("/api/consolidados-asistencia/{$consolidado}", ['ConsolidadoAsistenciaEstado' => 'OBSERVADO'])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaEstado']);

        $linea = DB::table('Compensaciones.DetalleLiquidacion')->where('LiquidacionDescuentoId', $id)->value('DetalleLiquidacionId');
        $this->patchJson("/api/detalles-liquidacion/{$linea}", ['DetalleLiquidacionImporte' => 100])->assertOk();
        $this->deleteJson("/api/liquidaciones-descuento/{$id}")->assertOk();
        $this->deleteJson("/api/liquidaciones-descuento/{$id}")->assertOk();   // anular es idempotente
        $this->getJson("/api/liquidaciones-descuento/{$id}")->assertOk()->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);

        // Anulada, se reutiliza: vuelve a GENERADO con las lineas recalculadas desde el consolidado.
        $regenerada = $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $consolidado])->assertCreated()->assertJsonPath('data.estado', 'GENERADO')->assertJsonPath('data.lineas', 2);
        $this->assertSame($id, $regenerada->json('data.id'));
        $this->assertEquals(0, $regenerada->json('data.importe_total'));
        $this->assertSame(1, DB::table('Compensaciones.LiquidacionDescuento')->where('ConsolidadoAsistenciaId', $consolidado)->count());
    }

    // ================================================================== Aprobar, remitir y anular

    public function test_aprobar_exige_el_importe_de_cada_linea_y_remitir_cierra_el_ciclo(): void
    {
        $id = $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $this->consolidadoLiquidable()])->assertCreated()->json('data.id');
        $lineas = DB::table('Compensaciones.DetalleLiquidacion')->where('LiquidacionDescuentoId', $id)->orderBy('DetalleLiquidacionId')->pluck('DetalleLiquidacionId');

        // Sin la remuneracion en el sistema, el importe lo completa quien liquida: sin el no se aprueba.
        $this->postJson("/api/liquidaciones-descuento/{$id}/aprobar")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no tiene importe'));
        $this->postJson("/api/liquidaciones-descuento/{$id}/remitir")->assertStatus(422);

        $this->patchJson("/api/detalles-liquidacion/{$lineas[0]}", ['DetalleLiquidacionImporte' => 200])->assertOk();
        $this->postJson("/api/liquidaciones-descuento/{$id}/aprobar")->assertStatus(422);
        $this->patchJson("/api/detalles-liquidacion/{$lineas[1]}", ['DetalleLiquidacionImporte' => 18.75])->assertOk();
        // El importe total es la suma de las lineas.
        $this->getJson("/api/liquidaciones-descuento/{$id}")->assertJsonPath('data.importe_total', 218.75);

        $this->postJson("/api/liquidaciones-descuento/{$id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->postJson("/api/liquidaciones-descuento/{$id}/aprobar")->assertStatus(422);
        $this->postJson("/api/liquidaciones-descuento/{$id}/remitir")->assertOk()->assertJsonPath('data.estado', 'REMITIDO');
        $this->postJson("/api/liquidaciones-descuento/{$id}/remitir")->assertStatus(422);
        // Remitida a planilla ya no se anula.
        $this->deleteJson("/api/liquidaciones-descuento/{$id}")->assertStatus(422);
    }

    public function test_las_lineas_solo_se_editan_mientras_la_liquidacion_esta_generada(): void
    {
        $id = $this->postJson('/api/liquidaciones-descuento', ['ConsolidadoAsistenciaId' => $this->consolidadoLiquidable()])->assertCreated()->json('data.id');
        $linea = DB::table('Compensaciones.DetalleLiquidacion')->where('LiquidacionDescuentoId', $id)->value('DetalleLiquidacionId');
        $nueva = ['LiquidacionDescuentoId' => $id, 'ConceptoDescuentoId' => $this->concepto('DESC_PERMISO'), 'DetalleLiquidacionCantidad' => 1, 'DetalleLiquidacionImporte' => 50];

        // Una linea nueva suma al total, y un concepto no se repite.
        $agregada = $this->postJson('/api/detalles-liquidacion', $nueva)->assertCreated()->json('data.id');
        $this->getJson("/api/liquidaciones-descuento/{$id}")->assertJsonPath('data.importe_total', 50)->assertJsonPath('data.lineas', 3);
        $this->postJson('/api/detalles-liquidacion', $nueva)->assertStatus(422)->assertJsonValidationErrors(['ConceptoDescuentoId']);
        $this->deleteJson("/api/detalles-liquidacion/{$agregada}")->assertOk();
        $this->getJson("/api/liquidaciones-descuento/{$id}")->assertJsonPath('data.importe_total', 0);

        foreach (['APROBADO', 'REMITIDO', 'ANULADO'] as $estado) {
            DB::table('Compensaciones.LiquidacionDescuento')->where('LiquidacionDescuentoId', $id)->update(['LiquidacionDescuentoEstado' => $estado]);
            $this->postJson('/api/detalles-liquidacion', $nueva)->assertStatus(422)->assertJsonValidationErrors(['LiquidacionDescuentoId']);
            $this->patchJson("/api/detalles-liquidacion/{$linea}", ['DetalleLiquidacionImporte' => 10])->assertStatus(422)->assertJsonValidationErrors(['LiquidacionDescuentoId']);
            $this->deleteJson("/api/detalles-liquidacion/{$linea}")->assertStatus(422);
        }
    }

    public function test_una_liquidacion_no_se_edita_y_solo_se_genera_aprueba_remite_o_anula(): void
    {
        $id = $this->liquidacionDeDescuentos();

        $this->patchJson("/api/liquidaciones-descuento/{$id}", ['LiquidacionDescuentoEstado' => 'APROBADO'])->assertStatus(405);
        $this->putJson("/api/liquidaciones-descuento/{$id}", [])->assertStatus(405);
        $this->postJson("/api/liquidaciones-descuento/{$id}/aprobar")->assertStatus(422);   // sin lineas
        $this->getJson('/api/liquidaciones-descuento/999999')->assertNotFound();
    }

    // ================================================================== Datos sembrados

    public function test_filtros_y_datos_sembrados_de_las_liquidaciones(): void
    {
        $this->getJson('/api/liquidaciones-descuento?por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/liquidaciones-descuento?estado=REMITIDO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lineas', 2);
        $this->getJson('/api/liquidaciones-descuento?estado=GENERADO')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/liquidaciones-descuento?periodo_asistencia_id='.$this->periodoDeAsistenciaId(8))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/liquidaciones-descuento?buscar=Castillo')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'REMITIDO');

        // El importe total de cada liquidacion es la suma de sus lineas.
        foreach (DB::table('Compensaciones.LiquidacionDescuento')->get() as $l) {
            $this->assertEquals(round((float) DB::table('Compensaciones.DetalleLiquidacion')->where('LiquidacionDescuentoId', $l->LiquidacionDescuentoId)->sum('DetalleLiquidacionImporte'), 2), $l->LiquidacionDescuentoImporteTotal);
        }
        // Las aprobadas y remitidas tienen todos sus importes.
        $this->assertSame(0, DB::table('Compensaciones.DetalleLiquidacion as d')->join('Compensaciones.LiquidacionDescuento as l', 'l.LiquidacionDescuentoId', '=', 'd.LiquidacionDescuentoId')
            ->whereIn('l.LiquidacionDescuentoEstado', ['APROBADO', 'REMITIDO'])->where('d.DetalleLiquidacionImporte', '<=', 0)->count());
        $this->getJson('/api/detalles-liquidacion?por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/detalles-liquidacion?concepto_descuento_id='.$this->concepto('DESC_FALTA'))->assertOk()->assertJsonCount(1, 'data');
    }
}
