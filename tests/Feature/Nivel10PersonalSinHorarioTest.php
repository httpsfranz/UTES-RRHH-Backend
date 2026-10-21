<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * El alta del trabajador no exige horario (lo define el jefe del establecimiento, RIT Art. 16), pero queda como pendiente
 * visible: marcado en el listado, filtrable y contado en "Por hacer" del inicio.
 */
class Nivel10PersonalSinHorarioTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function pendientes(string $tipo, string $dni): array
    {
        return $this->getJson("/api/trabajadores?pendiente={$tipo}&buscar={$dni}")->assertOk()->json('data');
    }

    public function test_el_trabajador_con_vinculo_y_sin_horario_queda_pendiente_hasta_que_se_le_asigna(): void
    {
        $trabajador = $this->nuevoTrabajador();
        $vinculo = $this->nuevoVinculo([], $trabajador);
        $dni = DB::table('Personal.Trabajador')->where('TrabajadorId', $trabajador)->value('TrabajadorNumeroDocumento');

        $filas = $this->pendientes('horario', $dni);
        $this->assertCount(1, $filas);
        $this->assertTrue($filas[0]['vinculos'][0]['sin_horario']);

        DB::table('Personal.AsignacionHorario')->insert([
            'VinculoLaboralId' => $vinculo, 'HorarioId' => (int) DB::table('Configuracion.Horario')->where('HorarioEstado', 1)->value('HorarioId'),
            'AsignacionHorarioFechaInicio' => '2026-01-01', 'AsignacionHorarioEstado' => 1,
        ]);

        $this->assertSame([], $this->pendientes('horario', $dni));
        $fila = $this->getJson("/api/trabajadores?buscar={$dni}")->assertOk()->json('data.0');
        $this->assertFalse($fila['vinculos'][0]['sin_horario']);
    }

    public function test_el_trabajador_sin_vinculo_vigente_queda_pendiente_de_vinculo(): void
    {
        $trabajador = $this->nuevoTrabajador();
        $dni = DB::table('Personal.Trabajador')->where('TrabajadorId', $trabajador)->value('TrabajadorNumeroDocumento');

        $this->assertCount(1, $this->pendientes('vinculo', $dni));
        $this->assertSame([], $this->pendientes('horario', $dni));
    }

    public function test_el_inicio_avisa_cuantos_no_tienen_horario(): void
    {
        $this->nuevoVinculo();

        $tareas = collect($this->getJson('/api/dashboard')->assertOk()->json('widgets'))->firstWhere('id', 'por_hacer')['items'];
        $tarea = collect($tareas)->firstWhere('titulo', 'Personal sin horario');

        $this->assertNotNull($tarea);
        $this->assertGreaterThanOrEqual(1, $tarea['cantidad']);
        $this->assertSame('/personal/trabajadores?pendiente=horario', $tarea['enlace']);
    }

    public function test_el_menu_cuenta_lo_mismo_que_la_bandeja_y_el_inicio(): void
    {
        $this->nuevoVinculo();

        $contadores = $this->getJson('/api/menu-pendientes')->assertOk()->json();
        $tareas = collect(collect($this->getJson('/api/dashboard')->json('widgets'))->firstWhere('id', 'por_hacer')['items']);

        $this->assertSame(count($this->getJson('/api/bandeja-solicitudes')->json('solicitudes')), $contadores['/solicitudes/bandeja']);
        $this->assertSame($tareas->firstWhere('titulo', 'Personal sin horario')['cantidad'], $contadores['/personal/trabajadores']);

        // El trabajador solo ve sus solicitudes en tramite; el pendiente de personal no es suyo.
        $this->actuarComo('mquispe');
        $this->assertArrayNotHasKey('/personal/trabajadores', $this->getJson('/api/menu-pendientes')->assertOk()->json());
    }
}
