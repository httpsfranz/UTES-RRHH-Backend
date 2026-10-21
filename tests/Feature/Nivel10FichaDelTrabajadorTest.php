<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): la ficha del trabajador reune sus vinculos y sus horarios, asi que el listado de
 * asignaciones de horario se puede pedir por trabajador (de todos sus vinculos).
 */
class Nivel10FichaDelTrabajadorTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    public function test_las_asignaciones_de_horario_se_filtran_por_trabajador(): void
    {
        $trabajador = $this->nuevoTrabajador();
        $vinculo = $this->nuevoVinculo([], $trabajador);
        $horario = (int) DB::table('Configuracion.Horario')->where('HorarioEstado', 1)->value('HorarioId');
        DB::table('Personal.AsignacionHorario')->insert(['VinculoLaboralId' => $vinculo, 'HorarioId' => $horario, 'AsignacionHorarioFechaInicio' => '2026-01-01', 'AsignacionHorarioEstado' => 1]);

        $filas = $this->getJson("/api/asignaciones-horario?trabajador_id={$trabajador}")->assertOk()->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame($vinculo, $filas[0]['vinculo_laboral_id']);
    }
}
