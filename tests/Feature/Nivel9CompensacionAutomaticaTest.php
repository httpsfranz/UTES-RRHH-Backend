<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Compensacion horaria sin horas digitadas (RIT, Art. 17): el jefe autoriza ANTES un dia de trabajo fuera de jornada, las
 * horas salen de la marcacion de salida frente al fin del turno, "compensar hasta" es el fin del mes siguiente y el
 * responsable valida; recien entonces el sobretiempo pasa a la asistencia del dia.
 */
class Nivel9CompensacionAutomaticaTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    /** Turno M (07:30-13:30) programado el 2026-10-14 con salida marcada a las 15:00 (90 min despues). */
    private function diaConSobretiempo(): int
    {
        $v = $this->nuevoVinculo();
        $periodo = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-10-13', '2026-10-15');
        $this->turnoProgramado($this->programacionDeTrabajador($periodo, $v), '2026-10-14', 'M');
        $manual = DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');
        foreach ([['ENTRADA', '2026-10-14 07:28:00'], ['SALIDA', '2026-10-14 15:00:00']] as [$tipo, $momento]) {
            DB::table('Asistencia.Marcacion')->insert(['VinculoLaboralId' => $v, 'MetodoMarcacionId' => $manual, 'MarcacionFechaHora' => $momento, 'MarcacionTipo' => $tipo, 'MarcacionOrigen' => 'MANUAL']);
        }

        return $v;
    }

    public function test_las_horas_salen_de_la_marcacion_de_salida_y_el_limite_es_el_mes_siguiente(): void
    {
        $v = $this->diaConSobretiempo();

        $id = $this->postJson('/api/compensaciones-horarias', [
            'VinculoLaboralId' => $v, 'CompensacionHorariaFechaTrabajo' => '2026-10-14', 'CompensacionHorariaAutorizadoPreviamente' => true,
            'CompensacionHorariaHorasGeneradas' => 8,   // se ignora: las horas no se digitan
        ])->assertCreated()
            ->assertJsonPath('data.fecha_limite', '2026-11-30')
            ->assertJsonPath('data.tipo.codigo', 'HORA_EXTRA')
            ->json('data.id');

        $fila = collect($this->getJson('/api/compensaciones-horarias?vinculo_laboral_id='.$v)->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertEquals(1.5, $fila['horas_generadas']);
        $this->assertNotNull($fila['asistencia_diaria_id']);

        $this->postJson("/api/compensaciones-horarias/{$id}/aprobar", ['Motivo' => 'Cobertura de emergencia'])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertSame(90, (int) DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $v)->whereDate('AsistenciaDiariaFecha', '2026-10-14')->value('AsistenciaDiariaMinutosExtra'));
    }

    public function test_un_dia_pasado_sin_autorizacion_previa_no_procede(): void
    {
        $v = $this->diaConSobretiempo();

        $this->postJson('/api/compensaciones-horarias', ['VinculoLaboralId' => $v, 'CompensacionHorariaFechaTrabajo' => '2026-10-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('CompensacionHorariaFechaTrabajo');
    }

    public function test_los_dias_potencialmente_compensables_se_detectan_solos(): void
    {
        $v = $this->diaConSobretiempo();

        $candidatas = collect($this->getJson('/api/compensaciones-horarias/candidatas?'.http_build_query(['eess_id' => $this->eessId(), 'desde' => '2026-10-14', 'hasta' => '2026-10-14']))
            ->assertOk()->json('data'));

        $fila = $candidatas->firstWhere('vinculo_id', $v);
        $this->assertSame(90, $fila['minutos']);
        $this->assertFalse($fila['procede']);   // sin autorizacion previa
    }
}
