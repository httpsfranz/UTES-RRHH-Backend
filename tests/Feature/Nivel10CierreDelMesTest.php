<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): "Cierre del mes" muestra en que punto esta el periodo (solicitudes pendientes,
 * consolidados, conformidad, liquidaciones) y resuelve en lote lo que antes se hacia fila por fila: la conformidad de
 * los consolidados sin incidencias y las liquidaciones de quien tiene descuentos.
 */
class Nivel10CierreDelMesTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private int $periodo;

    private int $limpio;

    private int $conFalta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periodo = $this->periodoDeAsistenciaId(12);
        $this->limpio = $this->nuevoVinculo();
        $this->conFalta = $this->nuevoVinculo();
        $estado = fn (string $codigo) => $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', $codigo);
        DB::table('Asistencia.AsistenciaDiaria')->insert([
            ['VinculoLaboralId' => $this->limpio, 'EstadoAsistenciaId' => $estado('ASISTIO'), 'AsistenciaDiariaFecha' => '2026-12-02', 'AsistenciaDiariaMinutosTardanza' => 0],
            ['VinculoLaboralId' => $this->conFalta, 'EstadoAsistenciaId' => $estado('FALTA'), 'AsistenciaDiariaFecha' => '2026-12-02', 'AsistenciaDiariaMinutosTardanza' => 0],
        ]);
        $this->consolidado($this->limpio, 12, ['ConsolidadoAsistenciaDiasTrabajados' => 1, 'ConsolidadoAsistenciaEstado' => 'GENERADO']);
        $this->consolidado($this->conFalta, 12, ['ConsolidadoAsistenciaDiasFalta' => 1, 'ConsolidadoAsistenciaEstado' => 'GENERADO']);
    }

    private function resumen(): array
    {
        return $this->getJson("/api/cierre-mes?periodo_asistencia_id={$this->periodo}&eess_id={$this->eessId()}")->assertOk()->json();
    }

    private function estadoDe(int $vinculo): string
    {
        return DB::table('Consolidacion.ConsolidadoAsistencia')->where(['PeriodoAsistenciaId' => $this->periodo, 'VinculoLaboralId' => $vinculo])->value('ConsolidadoAsistenciaEstado');
    }

    public function test_el_resumen_dice_que_falta_para_cerrar(): void
    {
        $resumen = $this->resumen();

        $this->assertSame('Diciembre 2026', $resumen['periodo']['etiqueta']);
        $this->assertGreaterThanOrEqual(2, $resumen['consolidados']['generado']);
        $this->assertGreaterThanOrEqual(1, $resumen['consolidados']['sin_incidencias']);
        $this->assertFalse($resumen['cierre']['puede_cerrar']);
        $this->assertGreaterThanOrEqual(2, $resumen['cierre']['sin_conformidad_en_la_red']);
    }

    public function test_la_conformidad_en_lote_solo_aprueba_lo_que_no_tiene_incidencias(): void
    {
        $respuesta = $this->postJson('/api/cierre-mes/conformidad', ['PeriodoAsistenciaId' => $this->periodo, 'EessId' => $this->eessId()])->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $respuesta['aprobados']);
        $this->assertSame('CONFORME', $this->estadoDe($this->limpio));
        $this->assertSame('GENERADO', $this->estadoDe($this->conFalta));
    }

    public function test_las_liquidaciones_en_lote_se_generan_una_sola_vez_y_luego_se_puede_cerrar(): void
    {
        DB::table('Consolidacion.ConsolidadoAsistencia')->where('PeriodoAsistenciaId', $this->periodo)->update(['ConsolidadoAsistenciaEstado' => 'CONFORME']);

        $primera = $this->postJson('/api/cierre-mes/liquidaciones', ['PeriodoAsistenciaId' => $this->periodo, 'EessId' => $this->eessId()])->assertOk()->json();
        $segunda = $this->postJson('/api/cierre-mes/liquidaciones', ['PeriodoAsistenciaId' => $this->periodo, 'EessId' => $this->eessId()])->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $primera['generadas']);
        $this->assertSame(0, $segunda['generadas']);
        $this->assertSame(0, $segunda['liquidaciones']['por_generar']);
        $this->assertTrue($segunda['cierre']['puede_cerrar']);
    }

    public function test_un_responsable_no_consulta_el_cierre_de_otro_establecimiento(): void
    {
        $this->actuarComo('resp.eess');

        $this->getJson("/api/cierre-mes?periodo_asistencia_id={$this->periodo}&eess_id={$this->eessId('EESS-LE-02')}")->assertForbidden();
    }
}
