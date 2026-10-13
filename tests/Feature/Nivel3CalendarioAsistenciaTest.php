<?php

namespace Tests\Feature;

use App\Support\AlcanceDeEess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Calendario mensual de asistencia de un establecimiento (GET /api/asistencia-diaria/calendario) y su PDF apaisado
 * (/calendario/pdf). Pantalla y PDF salen del mismo arreglo (CalendarioAsistenciaService): aqui se prueba el arreglo y que
 * el PDF se genere y se dibuje a partir de el.
 */
class Nivel3CalendarioAsistenciaTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function calendario(string $buscar, int $anio = 2026, int $mes = 9, ?int $eess = null): array
    {
        return $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $eess ?? $this->eessId(), 'anio' => $anio, 'mes' => $mes, 'buscar' => $buscar]))
            ->assertOk()->json();
    }

    private function dniDe(int $vinculo): string
    {
        return (string) DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->where('v.VinculoLaboralId', $vinculo)->value('t.TrabajadorNumeroDocumento');
    }

    private function estado(string $codigo): int
    {
        return $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', $codigo);
    }

    private function dia(int $vinculo, string $fecha, string $estado, array $extra = []): void
    {
        DB::table('Asistencia.AsistenciaDiaria')->insert($extra + ['VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado($estado), 'AsistenciaDiariaFecha' => $fecha]);
    }

    /** Programacion publicada del 7 al 30 de setiembre de 2026 y un turno del vinculo. */
    private function turno(int $vinculo, string $fecha, string $turno = 'M'): void
    {
        $periodo = $this->recordar('programacion', fn () => $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-09-07', '2026-09-30'));
        $pt = DB::table('Programacion.ProgramacionTrabajador')->where(['ProgramacionPeriodoId' => $periodo, 'VinculoLaboralId' => $vinculo])->value('ProgramacionTrabajadorId')
            ?? $this->programacionDeTrabajador($periodo, $vinculo);
        $this->turnoProgramado((int) $pt, $fecha, $turno);
    }

    // ------------------------------------------------------------------ los dias del mes

    public function test_los_dias_se_generan_segun_el_mes_con_su_dia_de_la_semana(): void
    {
        $octubre = $this->calendario('zzz', 2026, 10)['periodo'];
        $this->assertCount(31, $octubre['dias']);
        $this->assertSame('Octubre 2026', $octubre['etiqueta']);
        $this->assertSame(['01 Ju', '02 Vi', '03 Sa', '04 Do', '05 Lu'], array_map(fn ($d) => sprintf('%02d %s', $d['dia'], $d['dia_semana']), array_slice($octubre['dias'], 0, 5)));
        $this->assertTrue($octubre['dias'][2]['es_sabado']);
        $this->assertTrue($octubre['dias'][3]['es_domingo']);

        $noviembre = $this->calendario('zzz', 2026, 11)['periodo']['dias'];
        $this->assertCount(30, $noviembre);
        $this->assertSame('Do', $noviembre[0]['dia_semana']);   // 1 de noviembre de 2026 es domingo
        $this->assertSame('Lu', $noviembre[1]['dia_semana']);
    }

    public function test_febrero_tiene_28_dias_y_29_en_anio_bisiesto(): void
    {
        $this->assertCount(28, $this->calendario('zzz', 2027, 2)['periodo']['dias']);
        $this->assertCount(29, $this->calendario('zzz', 2028, 2)['periodo']['dias']);
    }

    public function test_el_calendario_no_laborable_marca_el_dia_de_la_columna(): void
    {
        DB::table('Soporte.CalendarioNoLaborable')->updateOrInsert(['CalendarioNoLaborableFecha' => '2026-09-08', 'MicroredId' => null], ['CalendarioNoLaborableTipo' => 'FERIADO', 'CalendarioNoLaborableDescripcion' => 'Prueba']);

        $dias = collect($this->calendario('zzz')['periodo']['dias'])->keyBy('fecha');

        $this->assertSame('FERIADO', $dias['2026-09-08']['no_laborable']['tipo']);
        $this->assertNull($dias['2026-09-09']['no_laborable']);
    }

    // ------------------------------------------------------------------ las celdas

    public function test_cada_celda_muestra_el_turno_y_el_estado_con_los_codigos_del_sistema(): void
    {
        $v = $this->nuevoVinculo();
        $this->turno($v, '2026-09-07');   // asistio
        $this->dia($v, '2026-09-07', 'ASISTIO', ['AsistenciaDiariaHoraEntrada' => '2026-09-07 07:30:00', 'AsistenciaDiariaHoraSalida' => '2026-09-07 13:30:00', 'AsistenciaDiariaMinutosTrabajados' => 360]);
        $this->turno($v, '2026-09-08');   // tardanza de 10 minutos de descuento
        $this->dia($v, '2026-09-08', 'TARDANZA', ['AsistenciaDiariaMinutosTardanza' => 10]);
        $this->turno($v, '2026-09-09', 'T');   // falta
        $this->dia($v, '2026-09-09', 'FALTA', ['AsistenciaDiariaMinutosFalta' => 360]);
        $this->dia($v, '2026-09-10', 'LICENCIA');   // sin turno: manda el estado
        $this->dia($v, '2026-09-11', 'FALTA_JUST');
        $this->dia($v, '2026-09-12', 'DESCANSO');
        $this->turno($v, '2026-09-14');   // programado, aun sin registro
        $fila = $this->calendario($this->dniDe($v))['trabajadores'][0];
        $celdas = $fila['celdas'];

        $this->assertSame(['M', '', 'normal'], [$celdas['2026-09-07']['linea1'], $celdas['2026-09-07']['linea2'], $celdas['2026-09-07']['categoria']]);
        $this->assertSame('07:30', $celdas['2026-09-07']['hora_entrada']);
        $this->assertSame(['M', '10', 'tardanza'], [$celdas['2026-09-08']['linea1'], $celdas['2026-09-08']['linea2'], $celdas['2026-09-08']['categoria']]);
        $this->assertSame(['T', 'FAL', 'falta'], [$celdas['2026-09-09']['linea1'], $celdas['2026-09-09']['linea2'], $celdas['2026-09-09']['categoria']]);
        $this->assertSame(['LIC', '', 'cobertura'], [$celdas['2026-09-10']['linea1'], $celdas['2026-09-10']['linea2'], $celdas['2026-09-10']['categoria']]);
        $this->assertSame('justificada', $celdas['2026-09-11']['categoria']);
        $this->assertSame('descanso', $celdas['2026-09-12']['categoria']);
        $this->assertSame(['M', 'programado', null], [$celdas['2026-09-14']['linea1'], $celdas['2026-09-14']['categoria'], $celdas['2026-09-14']['asistencia_id']]);
        $this->assertTrue($celdas['2026-09-15']['vacia']);
        $this->assertSame(30, count($celdas));
        $this->assertSame($celdas['2026-09-08']['asistencia_id'], (int) DB::table('Asistencia.AsistenciaDiaria')->where(['VinculoLaboralId' => $v])->whereDate('AsistenciaDiariaFecha', '2026-09-08')->value('AsistenciaDiariaId'));
    }

    public function test_sin_programacion_el_turno_sale_del_horario_asignado(): void
    {
        $v = $this->nuevoVinculo();
        DB::table('Personal.AsignacionHorario')->insert(['VinculoLaboralId' => $v, 'HorarioId' => $this->idPorCodigo('Configuracion.Horario', 'HorarioId', 'HorarioCodigo', 'HOR-ADM-LV'), 'AsignacionHorarioFechaInicio' => '2026-01-01']);

        $celdas = $this->calendario($this->dniDe($v))['trabajadores'][0]['celdas'];

        $this->assertSame('ADM-D', $celdas['2026-09-07']['linea1']);   // lunes
        $this->assertTrue($celdas['2026-09-12']['vacia']);             // sabado
    }

    public function test_la_leyenda_explica_los_estados_y_turnos_del_mes(): void
    {
        $v = $this->nuevoVinculo();
        $this->turno($v, '2026-09-07');

        $leyenda = $this->calendario($this->dniDe($v))['leyenda'];

        $this->assertContains('FAL', array_column($leyenda['estados'], 'abreviatura'));
        $this->assertSame('falta', collect($leyenda['estados'])->firstWhere('codigo', 'FALTA')['categoria']);
        $this->assertSame('cobertura', collect($leyenda['estados'])->firstWhere('codigo', 'VACACIONES')['categoria']);
        $this->assertSame('salida', collect($leyenda['estados'])->firstWhere('codigo', 'SALIDA_ANTIC')['categoria']);
        $this->assertSame('tardanza', collect($leyenda['estados'])->firstWhere('codigo', 'TARDANZA')['categoria']);
        $this->assertSame(['M'], array_column($leyenda['turnos'], 'codigo'));
        $this->assertCount(8, $leyenda['categorias']);
    }

    public function test_solo_aparecen_los_trabajadores_del_establecimiento_y_se_busca_por_dni_o_nombre(): void
    {
        $aqui = $this->nuevoVinculo();
        $otro = $this->nuevoVinculo(['EessId' => $this->eessId('EESS-EP-01')]);

        $filas = $this->calendario($this->dniDe($aqui))['trabajadores'];
        $this->assertSame([$aqui], array_column($filas, 'vinculo_id'));
        $this->assertSame($this->dniDe($aqui), $filas[0]['dni']);
        $this->assertSame(1, $filas[0]['n']);
        $this->assertSame([], $this->calendario($this->dniDe($otro))['trabajadores']);   // es de otro EESS
        $this->assertSame([$otro], array_column($this->calendario($this->dniDe($otro), eess: $this->eessId('EESS-EP-01'))['trabajadores'], 'vinculo_id'));
    }

    public function test_un_vinculo_terminado_antes_del_mes_no_aparece(): void
    {
        $v = $this->nuevoVinculo(['VinculoLaboralFechaFin' => '2026-08-31']);

        $this->assertSame([], $this->calendario($this->dniDe($v))['trabajadores']);
        $this->assertSame([$v], array_column($this->calendario($this->dniDe($v), 2026, 8)['trabajadores'], 'vinculo_id'));
    }

    public function test_el_calendario_tiene_como_cabecera_el_establecimiento_y_el_mes(): void
    {
        $c = $this->calendario('zzz', 2026, 10);

        $this->assertSame('EESS-LE-01', $c['eess']['codigo']);
        $this->assertSame('C.S. La Esperanza', $c['eess']['nombre']);
        $this->assertSame('Red de Salud Trujillo', $c['entidad']);
        $this->assertNotEmpty($c['generado_en']);
    }

    // ------------------------------------------------------------------ validacion y alcance

    public function test_el_establecimiento_y_el_mes_son_obligatorios_y_validos(): void
    {
        $this->getJson('/api/asistencia-diaria/calendario')->assertStatus(422)->assertJsonValidationErrors(['eess_id', 'anio', 'mes']);
        $this->getJson('/api/asistencia-diaria/calendario?eess_id=999999&anio=2026&mes=9')->assertStatus(422)->assertJsonValidationErrors(['eess_id']);
        $this->getJson('/api/asistencia-diaria/calendario?eess_id='.$this->eessId().'&anio=2026&mes=13')->assertStatus(422)->assertJsonValidationErrors(['mes']);
        $this->getJson('/api/asistencia-diaria/calendario/pdf?eess_id='.$this->eessId().'&anio=2026&mes=9&papel=a5')->assertStatus(422)->assertJsonValidationErrors(['papel']);
    }

    public function test_el_alcance_por_establecimiento_hoy_permite_todos_y_queda_listo_para_restringir(): void
    {
        $this->assertNull(AlcanceDeEess::permitidos());
        AlcanceDeEess::exigir($this->eessId());
        $this->addToAssertionCount(1);   // no lanza: sin roles, el administrador ve cualquier EESS
    }

    // ------------------------------------------------------------------ PDF

    public function test_el_pdf_se_genera_apaisado_con_el_nombre_del_establecimiento_y_mes(): void
    {
        $r = $this->get('/api/asistencia-diaria/calendario/pdf?eess_id='.$this->eessId().'&anio=2026&mes=10')->assertOk();

        $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('Asistencia_EESS-LE-01_2026-10.pdf', $r->headers->get('Content-Disposition'));
        $pdf = $r->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+841\.89\d*\s+595\.28\d*\s*\]/', $pdf);   // A4 apaisado
    }

    public function test_el_pdf_admite_a3_apaisado(): void
    {
        $r = $this->get('/api/asistencia-diaria/calendario/pdf?eess_id='.$this->eessId().'&anio=2026&mes=10&papel=a3')->assertOk();

        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+1190\.55\d*\s+841\.89\d*\s*\]/', $r->getContent());
    }

    public function test_la_plantilla_del_pdf_dibuja_el_mismo_calendario_que_entrega_la_api(): void
    {
        $v = $this->nuevoVinculo();
        $this->turno($v, '2026-09-08');
        $this->dia($v, '2026-09-08', 'TARDANZA', ['AsistenciaDiariaMinutosTardanza' => 20]);
        $c = $this->calendario($this->dniDe($v));

        $html = view('pdf.asistencia-calendario', ['c' => $c, 'papel' => 'a4'])->render();

        $this->assertStringContainsString('C.S. La Esperanza', $html);
        $this->assertStringContainsString('Septiembre 2026', $html);
        $this->assertStringContainsString($c['trabajadores'][0]['dni'], $html);
        $this->assertStringContainsString(e($c['trabajadores'][0]['nombre']), $html);
        $this->assertSame(30, substr_count($html, '<th style="width:') - 3);   // una columna por dia, mas N°, DNI y PERSONAL
        $this->assertStringContainsString('>20</span>', $html);   // los minutos de tardanza de la celda
    }
}
