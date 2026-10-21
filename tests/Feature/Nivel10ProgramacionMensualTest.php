<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): la programacion mensual se arma como una grilla trabajador x dia. Pintar una celda
 * crea sola la programacion del mes y la del trabajador, con las mismas reglas del RIT que un turno suelto; el mes
 * anterior se puede copiar y una programacion publicada ya no se pinta.
 */
class Nivel10ProgramacionMensualTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function pintar(int $vinculo, string $fecha, array $turnos, string $eess = 'EESS-LE-01')
    {
        return $this->putJson('/api/programacion-mensual/celda', ['eess_id' => $this->eessId($eess), 'VinculoLaboralId' => $vinculo, 'Fecha' => $fecha, 'TurnoIds' => $turnos]);
    }

    private function fila(array $grilla, int $vinculo): array
    {
        return collect($grilla['trabajadores'])->firstWhere('vinculo_id', $vinculo);
    }

    private function codigos(array $fila, string $fecha): array
    {
        return array_column($fila['celdas'][$fecha]['turnos'], 'codigo');
    }

    public function test_la_grilla_muestra_el_mes_con_los_turnos_disponibles_y_sin_programacion(): void
    {
        $v = $this->nuevoVinculo();

        $grilla = $this->getJson('/api/programacion-mensual?'.http_build_query(['eess_id' => $this->eessId(), 'anio' => 2026, 'mes' => 12]))->assertOk()->json();

        $this->assertCount(31, $grilla['periodo']['dias']);
        $this->assertNull($grilla['programacion']);
        $this->assertNull($grilla['bloqueo']);
        $this->assertContains('M', array_column($grilla['turnos'], 'codigo'));
        $this->assertSame([], $this->codigos($this->fila($grilla, $v), '2026-12-07'));
    }

    public function test_pintar_una_celda_crea_la_programacion_del_mes_y_la_del_trabajador(): void
    {
        $v = $this->nuevoVinculo();

        $grilla = $this->pintar($v, '2026-12-07', [$this->turnoId('M')])->assertOk()->json();

        $this->assertSame('BORRADOR', $grilla['programacion']['estado']);
        $this->assertSame(['M'], $this->codigos($this->fila($grilla, $v), '2026-12-07'));
        $this->assertEquals(6, $this->fila($grilla, $v)['horas']);
        $this->assertSame(['M' => 1], $grilla['cobertura']['2026-12-07']);
        $pt = DB::table('Programacion.ProgramacionTrabajador')->where('VinculoLaboralId', $v)->first();
        $this->assertSame('BORRADOR', $pt->ProgramacionTrabajadorEstado);
        $this->assertEquals(6, $pt->ProgramacionTrabajadorHorasProgramadas);
    }

    public function test_cambiar_y_liberar_una_celda(): void
    {
        $v = $this->nuevoVinculo();
        $this->pintar($v, '2026-12-07', [$this->turnoId('M')])->assertOk();

        $grilla = $this->pintar($v, '2026-12-07', [$this->turnoId('M'), $this->turnoId('T')])->assertOk()->json();
        $this->assertSame(['M', 'T'], $this->codigos($this->fila($grilla, $v), '2026-12-07'));

        $grilla = $this->pintar($v, '2026-12-07', [])->assertOk()->json();
        $this->assertSame([], $this->codigos($this->fila($grilla, $v), '2026-12-07'));
        // Sin turnos en el mes, el trabajador sale de la programacion.
        $this->assertFalse(DB::table('Programacion.ProgramacionTrabajador')->where('VinculoLaboralId', $v)->exists());
    }

    public function test_aplica_las_reglas_del_rit_y_no_deja_la_celda_a_medias(): void
    {
        $v = $this->nuevoVinculo();   // regimen "OTRO": no hace guardia

        $this->pintar($v, '2026-12-07', [$this->turnoId('M'), $this->turnoId('N')])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'guardia'));

        $this->assertFalse(DB::table('Programacion.ProgramacionTrabajador')->where('VinculoLaboralId', $v)->exists());

        // Un trabajador de otro establecimiento no se programa aqui.
        $otro = $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]);
        $this->pintar($otro, '2026-12-07', [$this->turnoId('M')])->assertStatus(422);
    }

    public function test_una_programacion_publicada_no_se_pinta(): void
    {
        $v = $this->nuevoVinculo();
        $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2027-01-01');

        $this->pintar($v, '2027-01-04', [$this->turnoId('M')])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'publicada'));
        $grilla = $this->getJson('/api/programacion-mensual?'.http_build_query(['eess_id' => $this->eessId(), 'anio' => 2027, 'mes' => 1]))->json();
        $this->assertStringContainsString('cambio de turno', $grilla['bloqueo']);
    }

    public function test_copiar_el_mes_anterior_conserva_el_dia_de_la_semana_y_no_pisa_lo_ya_pintado(): void
    {
        $v = $this->nuevoVinculo();
        $pt = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2026-11-01'), $v);
        $this->turnoProgramado($pt, '2026-11-03', 'T');   // martes
        $this->turnoProgramado($pt, '2026-11-30', 'M');   // lunes
        $this->turnoProgramado($pt, '2026-11-10', 'M');   // martes
        $this->pintar($v, '2026-12-08', [$this->turnoId('T')])->assertOk();   // ya pintado: no se pisa

        $resultado = $this->postJson('/api/programacion-mensual/copiar', ['eess_id' => $this->eessId(), 'anio' => 2026, 'mes' => 12])->assertOk()->json();

        $fila = $this->fila($resultado['grilla'], $v);
        $this->assertSame(['T'], $this->codigos($fila, '2026-12-01'));   // martes 1 <- martes 3 de noviembre
        $this->assertSame(['M'], $this->codigos($fila, '2026-12-28'));   // lunes 28 <- lunes 30 de noviembre
        $this->assertSame(['T'], $this->codigos($fila, '2026-12-08'));   // se conserva lo pintado (no toma la M del 10/11)
        $this->assertSame(2, $resultado['creados']);
    }
}
