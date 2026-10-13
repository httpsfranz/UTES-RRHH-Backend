<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Calendarios de programacion (GET /api/programacion-calendario/turnos y /ausencias): turnos programados, borradores,
 * guardias, conflictos con ausencias aprobadas y las propias ausencias (licencias, descansos, papeletas, vacaciones).
 */
class Nivel3CalendarioProgramacionTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function dniDe(int $vinculo): string
    {
        return (string) DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('v.VinculoLaboralId', $vinculo)->value('t.TrabajadorNumeroDocumento');
    }

    /** @return array<string,mixed> celdas del trabajador */
    private function celdas(string $vista, int $vinculo): array
    {
        $json = $this->getJson("/api/programacion-calendario/{$vista}?".http_build_query(['eess_id' => $this->eessId(), 'anio' => 2026, 'mes' => 9, 'buscar' => $this->dniDe($vinculo)]))
            ->assertOk()->json();
        $this->assertCount(1, $json['trabajadores']);

        return $json['trabajadores'][0]['celdas'];
    }

    private function turno(int $vinculo, string $fecha, string $estadoProgramacion = 'PUBLICADA', string $turno = 'M', array $extra = []): void
    {
        $periodo = $this->recordar('prog-cal-'.$estadoProgramacion, fn () => $this->periodoDeProgramacion($estadoProgramacion, 'EESS-LE-01', $estadoProgramacion === 'BORRADOR' ? '2026-09-08' : '2026-09-07', '2026-09-30'));
        $pt = DB::table('Programacion.ProgramacionTrabajador')->where(['ProgramacionPeriodoId' => $periodo, 'VinculoLaboralId' => $vinculo])->value('ProgramacionTrabajadorId')
            ?? $this->programacionDeTrabajador($periodo, $vinculo, $estadoProgramacion);
        $this->turnoProgramado((int) $pt, $fecha, $turno, $extra);
    }

    private function licencia(int $vinculo, string $inicio, string $fin, string $estado = 'APROBADO'): void
    {
        DB::table('Solicitudes.Licencia')->insert([
            'VinculoLaboralId' => $vinculo, 'TipoLicenciaId' => DB::table('Solicitudes.TipoLicencia')->value('TipoLicenciaId'),
            'LicenciaFechaInicio' => $inicio, 'LicenciaFechaFin' => $fin, 'LicenciaEstado' => $estado,
        ]);
    }

    public function test_los_turnos_programados_aparecen_como_turno_y_los_borradores_se_distinguen(): void
    {
        $v = $this->nuevoVinculo();
        $this->turno($v, '2026-09-07');
        $this->turno($v, '2026-09-08', 'BORRADOR');

        $celdas = $this->celdas('turnos', $v);

        $this->assertSame(['M', '', 'turno'], [$celdas['2026-09-07']['linea1'], $celdas['2026-09-07']['linea2'], $celdas['2026-09-07']['categoria']]);
        $this->assertSame(['M', 'BOR', 'borrador'], [$celdas['2026-09-08']['linea1'], $celdas['2026-09-08']['linea2'], $celdas['2026-09-08']['categoria']]);
        $this->assertTrue($celdas['2026-09-09']['vacia']);
    }

    public function test_un_turno_en_dia_de_ausencia_aprobada_es_conflicto_y_una_pendiente_no(): void
    {
        $v = $this->nuevoVinculo();
        $this->turno($v, '2026-09-10');
        $this->turno($v, '2026-09-11');
        $this->licencia($v, '2026-09-10', '2026-09-10');
        $this->licencia($v, '2026-09-11', '2026-09-11', 'PENDIENTE');

        $celdas = $this->celdas('turnos', $v);

        $this->assertSame('conflicto', $celdas['2026-09-10']['categoria']);
        $this->assertSame('LIC', $celdas['2026-09-10']['linea2']);
        $this->assertSame('turno', $celdas['2026-09-11']['categoria']);
    }

    public function test_las_ausencias_aprobadas_cubren_todo_su_rango_y_se_recortan_al_mes(): void
    {
        $v = $this->nuevoVinculo();
        $this->licencia($v, '2026-08-29', '2026-09-02');
        DB::table('Solicitudes.DescansoMedico')->insert(['VinculoLaboralId' => $v, 'DescansoMedicoFechaInicio' => '2026-09-02', 'DescansoMedicoFechaFin' => '2026-09-03', 'DescansoMedicoEstado' => 'APROBADO']);
        $this->licencia($v, '2026-09-20', '2026-09-21', 'RECHAZADO');
        $this->rolVacacional($this->periodoVacacional($v), '2026-09-14', 3);

        $celdas = $this->celdas('ausencias', $v);

        $this->assertSame('licencia', $celdas['2026-09-01']['categoria']);
        $this->assertSame(['descanso_medico', 'DM', '+1'], [$celdas['2026-09-02']['categoria'], $celdas['2026-09-02']['linea1'], $celdas['2026-09-02']['linea2']]);
        $this->assertTrue($celdas['2026-09-04']['vacia']);
        $this->assertTrue($celdas['2026-09-20']['vacia']);
        $this->assertSame('vacaciones_prog', $celdas['2026-09-15']['categoria']);
    }

    public function test_la_respuesta_trae_leyenda_y_valida_el_establecimiento(): void
    {
        $json = $this->getJson('/api/programacion-calendario/ausencias?'.http_build_query(['eess_id' => $this->eessId(), 'anio' => 2026, 'mes' => 9, 'buscar' => 'zzz']))->assertOk()->json();
        $this->assertCount(30, $json['periodo']['dias']);
        $this->assertCount(5, $json['leyenda']['estados']);

        $this->getJson('/api/programacion-calendario/turnos?anio=2026&mes=9')->assertStatus(422);
    }

    public function test_los_calendarios_de_programacion_y_ausencias_se_exportan_a_pdf_con_su_titulo(): void
    {
        foreach (['turnos' => 'Programacion', 'ausencias' => 'Ausencias-y-vacaciones'] as $vista => $prefijo) {
            $r = $this->get("/api/programacion-calendario/{$vista}/pdf?eess_id=".$this->eessId().'&anio=2026&mes=9&papel=a3')->assertOk();
            $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
            $this->assertStringContainsString("filename=\"{$prefijo}_", $r->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', $r->getContent());
        }
    }
}
