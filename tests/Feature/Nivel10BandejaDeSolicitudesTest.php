<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): una sola bandeja con todo lo que espera aprobacion (antes eran diez pantallas), lo
 * mas antiguo primero y dentro del alcance de cada usuario; el trabajador ve ahi "Mis solicitudes" sin poder aprobar.
 */
class Nivel10BandejaDeSolicitudesTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function bandeja(string $query = ''): array
    {
        return $this->getJson('/api/bandeja-solicitudes'.$query)->assertOk()->json();
    }

    public function test_reune_las_pendientes_de_todos_los_tipos_lo_mas_antiguo_primero(): void
    {
        $bandeja = $this->bandeja();

        $tipos = collect($bandeja['solicitudes'])->pluck('tipo')->unique();
        $this->assertGreaterThanOrEqual(5, $tipos->count());
        $this->assertTrue(collect($bandeja['solicitudes'])->every(fn ($s) => $s['estado'] === 'PENDIENTE' && $s['puede_aprobar'] && $s['trabajador'] && $s['dni']));
        $fechas = collect($bandeja['solicitudes'])->map(fn ($s) => $s['registrado'] ?? $s['fecha'])->all();
        $ordenadas = $fechas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
        $papeletas = collect($bandeja['resumen'])->firstWhere('tipo', 'papeletas');
        $this->assertSame(DB::table('Solicitudes.Papeleta')->where('PapeletaEstado', 'PENDIENTE')->count(), $papeletas['pendientes']);
    }

    public function test_resolverla_por_el_recurso_la_saca_de_la_bandeja(): void
    {
        $papeleta = collect($this->bandeja('?tipo=papeletas')['solicitudes'])->first();

        $this->postJson("/api/papeletas/{$papeleta['id']}/rechazar", ['Motivo' => 'Falta el documento de sustento'])->assertOk();

        $this->assertNotContains($papeleta['clave'], collect($this->bandeja('?tipo=papeletas')['solicitudes'])->pluck('clave')->all());
    }

    public function test_cada_responsable_ve_solo_su_establecimiento(): void
    {
        $this->actuarComo('resp.eess');

        $solicitudes = collect($this->bandeja()['solicitudes']);

        $this->assertNotEmpty($solicitudes);
        $this->assertSame(['C.S. La Esperanza'], $solicitudes->pluck('eess')->unique()->values()->all());
    }

    public function test_el_trabajador_ve_sus_solicitudes_sin_poder_aprobarlas(): void
    {
        $usuario = $this->actuarComo('trabajador');
        $suyos = DB::table('Personal.VinculoLaboral')->where('TrabajadorId', $usuario->TrabajadorId)->pluck('VinculoLaboralId')->map(fn ($id) => (int) $id)->all()
            ?: [$this->nuevoVinculo([], (int) $usuario->TrabajadorId)];
        DB::table('Asistencia.JustificacionFalta')->insert([
            'VinculoLaboralId' => $suyos[0], 'ConceptoJustificacionId' => DB::table('Asistencia.ConceptoJustificacion')->value('ConceptoJustificacionId'),
            'JustificacionFaltaFechaInicio' => '2026-09-01', 'JustificacionFaltaFechaFin' => '2026-09-01', 'JustificacionFaltaEstado' => 'PENDIENTE', 'UsuarioRegistroId' => $this->usuarioId('pgutierrez'),
        ]);

        $bandeja = $this->bandeja('?estado=TODOS');

        $this->assertTrue($bandeja['propias']);
        $this->assertNotEmpty($bandeja['solicitudes']);
        $this->assertTrue(collect($bandeja['solicitudes'])->every(fn ($s) => in_array($s['vinculo_id'], $suyos, true) && ! $s['puede_aprobar']));
    }
}
