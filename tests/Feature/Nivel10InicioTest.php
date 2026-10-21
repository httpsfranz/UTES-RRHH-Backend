<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): el inicio empieza por lo que el usuario tiene por hacer, cada indicador lleva a su
 * detalle y las solicitudes por resolver son las mismas que muestra la bandeja (antes los numeros no cuadraban).
 */
class Nivel10InicioTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function widget(array $panel, string $id): ?array
    {
        return collect($panel['widgets'])->firstWhere('id', $id);
    }

    public function test_el_inicio_de_gestion_empieza_por_lo_que_hay_por_hacer(): void
    {
        $panel = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('por_hacer', $panel['widgets'][0]['id']);
        $this->assertSame('tareas', $panel['widgets'][0]['tipo']);
        foreach ($panel['widgets'][0]['items'] as $tarea) {
            $this->assertGreaterThan(0, $tarea['cantidad']);
            $this->assertStringStartsWith('/', $tarea['enlace']);
        }
    }

    public function test_las_solicitudes_por_resolver_cuadran_con_la_bandeja(): void
    {
        DB::table('Asistencia.JustificacionFalta')->insert([
            'VinculoLaboralId' => $this->nuevoVinculo(), 'ConceptoJustificacionId' => DB::table('Asistencia.ConceptoJustificacion')->value('ConceptoJustificacionId'),
            'JustificacionFaltaFechaInicio' => '2026-09-01', 'JustificacionFaltaFechaFin' => '2026-09-01', 'JustificacionFaltaEstado' => 'PENDIENTE', 'UsuarioRegistroId' => $this->usuarioId('pgutierrez'),
        ]);
        $panel = $this->getJson('/api/dashboard')->assertOk()->json();
        $bandeja = $this->getJson('/api/bandeja-solicitudes')->assertOk()->json('solicitudes');

        $tarea = collect($this->widget($panel, 'por_hacer')['items'])->firstWhere('titulo', 'Solicitudes por resolver');
        $this->assertSame(count($bandeja), $tarea['cantidad']);
        $this->assertSame('/solicitudes/bandeja', $tarea['enlace']);
        // Ya no se repite como indicador: esta en "Por hacer".
        $this->assertNull(collect($this->widget($panel, 'resumen')['items'])->firstWhere('etiqueta', 'Solicitudes por resolver'));
    }

    public function test_cada_indicador_lleva_a_su_detalle(): void
    {
        $panel = $this->getJson('/api/dashboard')->assertOk()->json();

        $sinEnlace = collect($this->widget($panel, 'resumen')['items'])->whereNull('enlace')->pluck('etiqueta')->all();
        $this->assertSame([], $sinEnlace);
    }

    public function test_mis_solicitudes_del_trabajador_llevan_a_su_bandeja(): void
    {
        $this->actuarComo('mquispe');
        $panel = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame('/solicitudes/bandeja', $this->widget($panel, 'mis_solicitudes')['enlace']);
        $this->assertNull($this->widget($panel, 'por_hacer'));
    }
}
