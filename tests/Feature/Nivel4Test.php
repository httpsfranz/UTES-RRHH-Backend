<?php

namespace Tests\Feature;

use App\Models\Programacion\ProgramacionTrabajador;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel4Modulos;

/**
 * Nivel 4: programacion de trabajadores, ajustes de marcacion, detalle del consolidado y rol vacacional (RIT, Art. 16,
 * 20, 21, 68 a 74). La liquidacion de descuentos, que tambien es de este nivel, se prueba en Nivel4LiquidacionTest.
 */
class Nivel4Test extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel4Modulos::nivel4();
    }

    private function marcacion(int $vinculo, string $fechaHora = '2026-09-22 07:30:00', string $tipo = 'ENTRADA'): int
    {
        return (int) DB::table('Asistencia.Marcacion')->insertGetId([
            'VinculoLaboralId' => $vinculo, 'MarcacionFechaHora' => $fechaHora, 'MarcacionTipo' => $tipo,
            'MetodoMarcacionId' => $this->idPorCodigo('Biometria.MetodoMarcacion', 'MetodoMarcacionId', 'MetodoMarcacionCodigo', 'ROSTRO'),
        ], 'MarcacionId');
    }

    // ================================================================== Programacion de un trabajador

    public function test_solo_se_programa_a_los_trabajadores_del_establecimiento_en_una_programacion_en_borrador(): void
    {
        $borrador = $this->periodoDeProgramacion('BORRADOR');
        $vinculo = $this->nuevoVinculo();

        // Una programacion publicada, cerrada o anulada ya no se modifica (RIT, Art. 16).
        foreach (['PUBLICADA', 'CERRADA', 'ANULADA'] as $i => $estado) {
            $periodo = $this->periodoDeProgramacion($estado, 'EESS-LE-01', '2027-0'.($i + 1).'-01');
            $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $periodo, 'VinculoLaboralId' => $vinculo])
                ->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoId']);
        }
        $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2027-05-01'), 'VinculoLaboralId' => $vinculo])
            ->assertJsonPath('errors.ProgramacionPeriodoId.0', fn ($m) => str_contains($m, 'Art. 16'));

        // Otro establecimiento, o un vinculo que ya habia terminado antes del periodo.
        $deOtroEstablecimiento = $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]);
        $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $borrador, 'VinculoLaboralId' => $deOtroEstablecimiento])
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
        $cesado = $this->nuevoVinculo(['VinculoLaboralFechaFin' => '2026-06-30', 'VinculoLaboralMotivoCese' => 'Renuncia']);
        $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $borrador, 'VinculoLaboralId' => $cesado])
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);

        $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $borrador, 'VinculoLaboralId' => $vinculo])
            ->assertCreated()->assertJsonPath('data.estado', 'BORRADOR')->assertJsonPath('data.periodo.estado', 'BORRADOR')
            ->assertJsonPath('data.trabajador.id', (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $vinculo)->value('TrabajadorId'));
        $this->postJson('/api/programaciones-trabajador', ['ProgramacionPeriodoId' => $borrador, 'VinculoLaboralId' => $vinculo])
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
    }

    public function test_las_horas_y_el_estado_los_calcula_el_sistema(): void
    {
        $pt = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR'), $this->nuevoVinculo());

        $this->patchJson("/api/programaciones-trabajador/{$pt}", ['ProgramacionTrabajadorHorasProgramadas' => 120, 'ProgramacionTrabajadorEstado' => 'CERRADA'])
            ->assertStatus(422)->assertJsonValidationErrors(['ProgramacionTrabajadorHorasProgramadas', 'ProgramacionTrabajadorEstado']);

        // Las horas son la suma de la duracion de sus turnos: manana (6 h) + tarde (6 h) + guardia diurna (12 h).
        $this->turnoProgramado($pt, '2026-11-02', 'M');
        $this->assertEquals(6, $this->getJson("/api/programaciones-trabajador/{$pt}")->json('data.horas_programadas'));
        $this->turnoProgramado($pt, '2026-11-03', 'T');
        $guardia = $this->turnoProgramado($pt, '2026-11-04', 'G12-D');
        $r = $this->getJson("/api/programaciones-trabajador/{$pt}")->assertOk();
        $this->assertEquals(24, $r->json('data.horas_programadas'));
        $this->assertSame(3, $r->json('data.turnos_programados'));

        // Un turno anulado ya no suma.
        DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $guardia)->update(['TurnoProgramadoEstado' => 'ANULADO']);
        ProgramacionTrabajador::query()->find($pt)->recalcularHoras();
        $this->assertEquals(12, $this->getJson("/api/programaciones-trabajador/{$pt}")->json('data.horas_programadas'));
    }

    public function test_con_turnos_cargados_no_cambia_de_trabajador_y_al_retirarlo_se_retiran_sus_turnos(): void
    {
        $periodo = $this->periodoDeProgramacion('BORRADOR');
        $pt = $this->programacionDeTrabajador($periodo, $this->nuevoVinculo());
        $turno = $this->turnoProgramado($pt, '2026-11-02');

        $this->patchJson("/api/programaciones-trabajador/{$pt}", ['VinculoLaboralId' => $this->nuevoVinculo()])
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
        $this->patchJson("/api/programaciones-trabajador/{$pt}", ['ProgramacionPeriodoId' => $this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2027-02-01')])
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);

        $this->deleteJson("/api/programaciones-trabajador/{$pt}")->assertOk();
        $this->assertNull(DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $pt)->first());
        $this->assertNull(DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $turno)->first());
    }

    public function test_una_programacion_publicada_no_se_modifica_ni_se_retira(): void
    {
        $pt = $this->programacionDeTrabajador($this->periodoDeProgramacion('PUBLICADA'), $this->nuevoVinculo());

        $this->patchJson("/api/programaciones-trabajador/{$pt}", ['ProgramacionTrabajadorObservacion' => 'Cambio'])
            ->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoId']);
        $this->deleteJson("/api/programaciones-trabajador/{$pt}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Art. 16'));
        $this->assertNotNull(DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $pt)->first());
    }

    public function test_el_estado_del_periodo_se_propaga_a_los_trabajadores_y_sus_turnos(): void
    {
        $periodo = $this->periodoDeProgramacion('BORRADOR');
        $pt = $this->programacionDeTrabajador($periodo, $this->nuevoVinculo());
        $turno = $this->turnoProgramado($pt, '2026-11-02');
        $estado = fn () => DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $pt)->value('ProgramacionTrabajadorEstado');

        $this->postJson("/api/programaciones-periodo/{$periodo}/publicar")->assertOk();
        $this->assertSame('PUBLICADA', $estado());
        $this->postJson("/api/programaciones-periodo/{$periodo}/cerrar")->assertOk();
        $this->assertSame('CERRADA', $estado());

        // Anular arrastra a los trabajadores y a los turnos pendientes, pero no a los ya cumplidos.
        $otro = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2027-01-01');
        $otroPt = $this->programacionDeTrabajador($otro, $this->nuevoVinculo());
        $pendiente = $this->turnoProgramado($otroPt, '2027-01-04');
        $cumplido = $this->turnoProgramado($otroPt, '2027-01-05', 'M', ['TurnoProgramadoEstado' => 'CUMPLIDO']);
        $this->deleteJson("/api/programaciones-periodo/{$otro}")->assertOk();

        $this->assertSame('ANULADA', DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $otroPt)->value('ProgramacionTrabajadorEstado'));
        $this->assertSame('ANULADO', DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $pendiente)->value('TurnoProgramadoEstado'));
        $this->assertSame('CUMPLIDO', DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $cumplido)->value('TurnoProgramadoEstado'));
        $this->assertEquals(6, DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $otroPt)->value('ProgramacionTrabajadorHorasProgramadas'));
        $this->assertNotNull($turno);
    }

    public function test_filtros_y_datos_sembrados_de_la_programacion_de_trabajadores(): void
    {
        $this->getJson('/api/programaciones-trabajador?por_pagina=100')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/programaciones-trabajador?estado=BORRADOR&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/programaciones-trabajador?estado=ANULADA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/programaciones-trabajador?eess_id='.$this->eessId('EESS-LE-01').'&estado=PUBLICADA')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/programaciones-trabajador?buscar=Cabrera')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.turnos_programados', 12);
        $this->getJson('/api/programaciones-trabajador?buscar=Quispe&estado=PUBLICADA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.horas_programadas', 84);
    }

    // ================================================================== Ajuste de marcacion

    public function test_aprobar_el_ajuste_corrige_la_marcacion_y_avisa_a_quien_lo_registro(): void
    {
        $vinculo = $this->nuevoVinculo();
        $marcacion = $this->marcacion($vinculo);
        $usuario = $this->usuarioId('rvargas');

        $id = $this->postJson('/api/ajustes-marcacion', [
            'MarcacionId' => $marcacion, 'UsuarioId' => $usuario, 'AjusteMarcacionFechaHoraNueva' => '2026-09-22T07:20', 'AjusteMarcacionMotivo' => 'Reloj atrasado',
        ])->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->assertJsonPath('data.fecha_hora_anterior', '2026-09-22 07:30:00')
            ->assertJsonPath('data.fecha_hora_nueva', '2026-09-22 07:20:00')->json('data.id');

        $this->postJson("/api/ajustes-marcacion/{$id}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez'), 'Motivo' => 'Constatado'])
            ->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertSame('2026-09-22 07:20:00', date('Y-m-d H:i:s', strtotime(DB::table('Asistencia.Marcacion')->where('MarcacionId', $marcacion)->value('MarcacionFechaHora'))));
        $this->assertSame(1, DB::table('Soporte.Notificacion')->where('UsuarioId', $usuario)->where('NotificacionTipo', 'like', 'SOLICITUD_DE_AJUSTE%APROBADA')->count());

        // Aplicado: ya no se modifica, ni se aprueba de nuevo, ni se anula.
        $this->patchJson("/api/ajustes-marcacion/{$id}", ['AjusteMarcacionMotivo' => 'Otro'])->assertStatus(422)->assertJsonValidationErrors(['AjusteMarcacionEstado']);
        $this->postJson("/api/ajustes-marcacion/{$id}/aprobar", ['UsuarioId' => $usuario])->assertStatus(422);
        $this->deleteJson("/api/ajustes-marcacion/{$id}")->assertStatus(422);
    }

    public function test_sin_fecha_nueva_el_ajuste_aprobado_invalida_la_marcacion(): void
    {
        $marcacion = $this->marcacion($this->nuevoVinculo());
        $id = $this->postJson('/api/ajustes-marcacion', ['MarcacionId' => $marcacion, 'UsuarioId' => $this->usuarioId(), 'AjusteMarcacionMotivo' => 'Lectura duplicada'])
            ->assertCreated()->assertJsonPath('data.fecha_hora_nueva', null)->json('data.id');

        $this->postJson("/api/ajustes-marcacion/{$id}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertOk();
        $this->assertEquals(0, DB::table('Asistencia.Marcacion')->where('MarcacionId', $marcacion)->value('MarcacionEsValida'));

        // Una marcacion invalidada ya no admite otro ajuste.
        $this->postJson('/api/ajustes-marcacion', ['MarcacionId' => $marcacion, 'UsuarioId' => $this->usuarioId(), 'AjusteMarcacionMotivo' => 'Otra vez'])
            ->assertStatus(422)->assertJsonValidationErrors(['MarcacionId']);
    }

    public function test_rechazar_pide_motivo_deja_la_marcacion_igual_y_permite_pedir_otro_ajuste(): void
    {
        $marcacion = $this->marcacion($this->nuevoVinculo());
        $base = ['MarcacionId' => $marcacion, 'UsuarioId' => $this->usuarioId(), 'AjusteMarcacionFechaHoraNueva' => '2026-09-22 07:00:00', 'AjusteMarcacionMotivo' => 'Entró antes'];
        $id = $this->postJson('/api/ajustes-marcacion', $base)->assertCreated()->json('data.id');

        $this->postJson("/api/ajustes-marcacion/{$id}/rechazar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertStatus(422)->assertJsonValidationErrors(['Motivo']);
        $this->postJson("/api/ajustes-marcacion/{$id}/rechazar", ['UsuarioId' => $this->usuarioId('pgutierrez'), 'Motivo' => 'Sin sustento'])->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->assertSame('2026-09-22 07:30:00', date('Y-m-d H:i:s', strtotime(DB::table('Asistencia.Marcacion')->where('MarcacionId', $marcacion)->value('MarcacionFechaHora'))));

        // Resuelta la solicitud, la marcacion admite una nueva.
        $this->postJson('/api/ajustes-marcacion', $base)->assertCreated();
    }

    public function test_el_ajuste_no_se_aplica_si_la_marcacion_cambio_o_el_periodo_se_cerro(): void
    {
        $vinculo = $this->nuevoVinculo();
        $marcacion = $this->marcacion($vinculo);
        $base = ['UsuarioId' => $this->usuarioId(), 'AjusteMarcacionFechaHoraNueva' => '2026-09-22 07:20:00', 'AjusteMarcacionMotivo' => 'Reloj atrasado'];
        $aprobador = ['UsuarioId' => $this->usuarioId('pgutierrez')];

        $cambiada = $this->postJson('/api/ajustes-marcacion', $base + ['MarcacionId' => $marcacion])->assertCreated()->json('data.id');
        DB::table('Asistencia.Marcacion')->where('MarcacionId', $marcacion)->update(['MarcacionFechaHora' => '2026-09-22 07:45:00']);
        $this->postJson("/api/ajustes-marcacion/{$cambiada}/aprobar", $aprobador)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cambió'));
        $this->assertSame('PENDIENTE', DB::table('Asistencia.AjusteMarcacion')->where('AjusteMarcacionId', $cambiada)->value('AjusteMarcacionEstado'));

        // Un periodo de asistencia cerrado es inmutable, tambien para los ajustes ya pedidos.
        DB::table('Asistencia.AjusteMarcacion')->where('AjusteMarcacionId', $cambiada)->update(['AjusteMarcacionFechaHoraAnterior' => '2026-09-22 07:45:00']);
        DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaId', $this->periodoDeAsistenciaId(9))->update(['PeriodoAsistenciaEstado' => 'CERRADO']);
        $this->postJson("/api/ajustes-marcacion/{$cambiada}/aprobar", $aprobador)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cerrado'));
        $this->postJson('/api/ajustes-marcacion', $base + ['MarcacionId' => $this->marcacion($vinculo, '2026-09-23 07:30:00')])->assertStatus(422)->assertJsonValidationErrors(['MarcacionId']);
    }

    public function test_el_ajuste_no_puede_duplicar_otra_marcacion_del_mismo_tipo(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->marcacion($vinculo, '2026-09-22 07:20:00');
        $marcacion = $this->marcacion($vinculo, '2026-09-22 07:30:00');

        $this->postJson('/api/ajustes-marcacion', ['MarcacionId' => $marcacion, 'UsuarioId' => $this->usuarioId(), 'AjusteMarcacionFechaHoraNueva' => '2026-09-22 07:20:00', 'AjusteMarcacionMotivo' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['AjusteMarcacionFechaHoraNueva']);
    }

    public function test_filtros_y_datos_sembrados_de_los_ajustes(): void
    {
        $this->getJson('/api/ajustes-marcacion?por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/ajustes-marcacion?estado=PENDIENTE&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ajustes-marcacion?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/ajustes-marcacion?buscar=Rojas')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'RECHAZADO');
        $aprobado = $this->getJson('/api/ajustes-marcacion?estado=APROBADO')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('2026-09-30 13:35:00', $aprobado['fecha_hora_anterior']);
        $this->assertSame('2026-09-30 13:30:00', $aprobado['marcacion']['fecha_hora']);   // la marcacion ya quedo corregida
    }

    // ================================================================== Detalle del consolidado

    private function asistencia(int $vinculo, string $fecha, string $estado, array $extra = []): int
    {
        return (int) DB::table('Asistencia.AsistenciaDiaria')->insertGetId($extra + [
            'VinculoLaboralId' => $vinculo, 'AsistenciaDiariaFecha' => $fecha,
            'EstadoAsistenciaId' => $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', $estado),
        ], 'AsistenciaDiariaId');
    }

    public function test_generar_el_consolidado_fotografia_la_asistencia_diaria_y_el_detalle_explica_los_totales(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->asistencia($vinculo, '2026-09-21', 'ASISTIO', ['AsistenciaDiariaMinutosExtra' => 30]);
        $this->asistencia($vinculo, '2026-09-22', 'TARDANZA', ['AsistenciaDiariaMinutosTardanza' => 11]);
        $falta = $this->asistencia($vinculo, '2026-09-23', 'FALTA');
        $this->asistencia($vinculo, '2026-09-24', 'FALTA_JUST');
        $this->asistencia($vinculo, '2026-09-25', 'DESCANSO');
        $setiembre = $this->periodoDeAsistenciaId(9);

        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])->assertOk();
        $consolidado = DB::table('Consolidacion.ConsolidadoAsistencia')->where(['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])->value('ConsolidadoAsistenciaId');

        $detalle = $this->getJson("/api/detalles-consolidado?consolidado_asistencia_id={$consolidado}&por_pagina=100")->assertOk()->assertJsonCount(5, 'data')->json('data');
        $porFecha = collect($detalle)->keyBy('fecha');
        $this->assertSame('ASISTIO', $porFecha['2026-09-21']['estado']);
        $this->assertSame(30, $porFecha['2026-09-21']['minutos_extra']);
        $this->assertSame(11, $porFecha['2026-09-22']['minutos_tardanza']);
        $this->assertSame($falta, $porFecha['2026-09-23']['asistencia_diaria_id']);
        $this->assertTrue($porFecha['2026-09-24']['es_justificada']);
        $this->assertFalse($porFecha['2026-09-23']['es_justificada']);

        // Total y detalle coinciden: las faltas justificadas del consolidado son los dias justificados del detalle.
        $c = $this->getJson("/api/consolidados-asistencia/{$consolidado}")->json('data');
        $this->assertEquals($c['dias_falta_justificada'], collect($detalle)->where('es_justificada', true)->count());
        $this->assertSame($c['minutos_tardanza'], collect($detalle)->sum('minutos_tardanza'));

        // Regenerar reemplaza el detalle por la foto nueva, sin duplicar.
        $this->asistencia($vinculo, '2026-09-26', 'ASISTIO');
        DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $falta)->update(['EstadoAsistenciaId' => $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', 'ASISTIO')]);
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])->assertOk();
        $this->getJson("/api/detalles-consolidado?consolidado_asistencia_id={$consolidado}&por_pagina=100")->assertOk()->assertJsonCount(6, 'data');
        $this->getJson("/api/detalles-consolidado?consolidado_asistencia_id={$consolidado}&estado=FALTA")->assertOk()->assertJsonCount(0, 'data');

        // La foto referencia la asistencia diaria: una asistencia ya consolidada no se elimina.
        $this->deleteJson("/api/asistencia-diaria/{$falta}")->assertStatus(409);
    }

    public function test_el_detalle_solo_se_toca_mientras_el_consolidado_no_esta_conforme_ni_cerrado(): void
    {
        $consolidado = $this->consolidado($this->nuevoVinculo(), 10);
        $detalle = $this->postJson('/api/detalles-consolidado', ['ConsolidadoAsistenciaId' => $consolidado, 'DetalleConsolidadoFecha' => '2026-10-05', 'DetalleConsolidadoEstado' => 'ASISTIO'])
            ->assertCreated()->json('data.id');

        foreach (['CONFORME', 'CERRADO'] as $estado) {
            DB::table('Consolidacion.ConsolidadoAsistencia')->where('ConsolidadoAsistenciaId', $consolidado)->update(['ConsolidadoAsistenciaEstado' => $estado]);
            $this->postJson('/api/detalles-consolidado', ['ConsolidadoAsistenciaId' => $consolidado, 'DetalleConsolidadoFecha' => '2026-10-06', 'DetalleConsolidadoEstado' => 'ASISTIO'])
                ->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
            $this->patchJson("/api/detalles-consolidado/{$detalle}", ['DetalleConsolidadoMinutosExtra' => 10])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
            $this->deleteJson("/api/detalles-consolidado/{$detalle}")->assertStatus(422);
        }

        DB::table('Consolidacion.ConsolidadoAsistencia')->where('ConsolidadoAsistenciaId', $consolidado)->update(['ConsolidadoAsistenciaEstado' => 'OBSERVADO']);
        $this->deleteJson("/api/detalles-consolidado/{$detalle}")->assertOk();
        $this->assertNull(DB::table('Consolidacion.DetalleConsolidado')->where('DetalleConsolidadoId', $detalle)->first());

        // Un periodo de asistencia cerrado tambien lo congela.
        $agosto = $this->consolidado($this->nuevoVinculo(), 8, ['ConsolidadoAsistenciaEstado' => 'GENERADO']);
        $this->postJson('/api/detalles-consolidado', ['ConsolidadoAsistenciaId' => $agosto, 'DetalleConsolidadoFecha' => '2026-08-05', 'DetalleConsolidadoEstado' => 'ASISTIO'])
            ->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaId']);
    }

    public function test_la_asistencia_diaria_enlazada_es_del_mismo_trabajador_y_dia_y_eliminar_el_consolidado_elimina_su_detalle(): void
    {
        $vinculo = $this->nuevoVinculo();
        $consolidado = $this->consolidado($vinculo, 10);
        $base = ['ConsolidadoAsistenciaId' => $consolidado, 'DetalleConsolidadoFecha' => '2026-10-05', 'DetalleConsolidadoEstado' => 'ASISTIO'];

        $deOtro = $this->asistencia($this->nuevoVinculo(), '2026-10-05', 'ASISTIO');
        $deOtroDia = $this->asistencia($vinculo, '2026-10-06', 'ASISTIO');
        $correcta = $this->asistencia($vinculo, '2026-10-05', 'ASISTIO');
        $this->postJson('/api/detalles-consolidado', $base + ['AsistenciaDiariaId' => $deOtro])->assertStatus(422)->assertJsonValidationErrors(['AsistenciaDiariaId']);
        $this->postJson('/api/detalles-consolidado', $base + ['AsistenciaDiariaId' => $deOtroDia])->assertStatus(422)->assertJsonValidationErrors(['AsistenciaDiariaId']);
        $detalle = $this->postJson('/api/detalles-consolidado', $base + ['AsistenciaDiariaId' => $correcta])->assertCreated()->json('data.id');

        $this->deleteJson("/api/consolidados-asistencia/{$consolidado}")->assertOk();
        $this->assertNull(DB::table('Consolidacion.DetalleConsolidado')->where('DetalleConsolidadoId', $detalle)->first());
    }

    public function test_los_consolidados_cerrados_sembrados_tienen_un_detalle_que_coincide_con_sus_totales(): void
    {
        $agosto = DB::table('Consolidacion.ConsolidadoAsistencia')->where('PeriodoAsistenciaId', $this->periodoDeAsistenciaId(8))->get();
        $this->assertCount(4, $agosto);
        foreach ($agosto as $c) {
            $dias = DB::table('Consolidacion.DetalleConsolidado')->where('ConsolidadoAsistenciaId', $c->ConsolidadoAsistenciaId)->get();
            $this->assertEquals($c->ConsolidadoAsistenciaDiasFalta, $dias->where('DetalleConsolidadoEstado', 'FALTA')->count());
            $this->assertEquals($c->ConsolidadoAsistenciaDiasFaltaJustificada, $dias->where('DetalleConsolidadoEsJustificada', 1)->count());
            $this->assertEquals($c->ConsolidadoAsistenciaDiasTrabajados, $dias->whereIn('DetalleConsolidadoEstado', ['ASISTIO', 'TARDANZA'])->count());
            $this->assertEquals($c->ConsolidadoAsistenciaMinutosTardanza, $dias->sum('DetalleConsolidadoMinutosTardanza'));
            $this->assertEquals($c->ConsolidadoAsistenciaMinutosExtra, $dias->sum('DetalleConsolidadoMinutosExtra'));
        }
        // Setiembre se genero con el servicio de la API: cada consolidado tiene tantos dias como asistencia diaria.
        foreach (DB::table('Consolidacion.ConsolidadoAsistencia')->where('PeriodoAsistenciaId', $this->periodoDeAsistenciaId(9))->get() as $c) {
            $this->assertSame(
                DB::table('Asistencia.AsistenciaDiaria')->where('VinculoLaboralId', $c->VinculoLaboralId)->whereBetween('AsistenciaDiariaFecha', ['2026-09-01', '2026-09-30'])->count(),
                DB::table('Consolidacion.DetalleConsolidado')->where('ConsolidadoAsistenciaId', $c->ConsolidadoAsistenciaId)->count(),
            );
        }
        $this->getJson('/api/detalles-consolidado?estado=FALTA&por_pagina=100')->assertOk();
        $this->getJson('/api/detalles-consolidado?es_justificada=1&por_pagina=100')->assertOk();
    }

    // ================================================================== Rol vacacional

    private function rol(int $periodo, array $extra = []): array
    {
        return $extra + ['PeriodoVacacionalId' => $periodo, 'RolVacacionalFechaProgramada' => '2026-12-07', 'RolVacacionalDias' => 15];
    }

    public function test_el_descanso_programado_calcula_su_fin_y_no_pasa_de_los_dias_ganados(): void
    {
        $periodo = $this->periodoVacacional($this->nuevoVinculo());

        $this->postJson('/api/roles-vacacionales', $this->rol($periodo))->assertCreated()
            ->assertJsonPath('data.fecha_fin_programada', '2026-12-21')->assertJsonPath('data.estado', 'PROGRAMADO')->assertJsonPath('data.periodo_vacacional.dias_ganados', 30);
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2027-01-04']))->assertCreated();   // completa los 30 dias

        // RIT, Art. 68: 30 dias calendario por anio completo de servicios.
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2027-03-01', 'RolVacacionalDias' => 7]))
            ->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalDias']);
    }

    public function test_el_descanso_se_fracciona_en_tramos_de_siete_dias_o_mas_y_hasta_siete_dias_menores(): void
    {
        $periodo = $this->periodoVacacional($this->nuevoVinculo());

        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalDias' => 20]))->assertCreated();
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2027-02-01', 'RolVacacionalDias' => 5]))->assertCreated();
        // Con 3 dias mas, los tramos menores de 7 suman 8: pasa del tope de 7 dias (RIT, Art. 72).
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2027-03-01', 'RolVacacionalDias' => 3]))
            ->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalDias']);
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2027-03-01', 'RolVacacionalDias' => 2]))->assertCreated();
    }

    public function test_el_descanso_no_se_cruza_con_otro_del_mismo_trabajador_ni_se_programa_en_un_periodo_cerrado(): void
    {
        $vinculo = $this->nuevoVinculo();
        $periodo = $this->periodoVacacional($vinculo);
        $anterior = $this->periodoVacacional($vinculo, ['PeriodoVacacionalAnio' => 2025, 'PeriodoVacacionalFechaInicio' => '2025-01-01', 'PeriodoVacacionalFechaFin' => '2025-12-31']);
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo))->assertCreated();

        // Mismo periodo o el de otro anio del mismo trabajador: no puede estar de vacaciones dos veces a la vez.
        $this->postJson('/api/roles-vacacionales', $this->rol($periodo, ['RolVacacionalFechaProgramada' => '2026-12-14']))->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalFechaProgramada']);
        $this->postJson('/api/roles-vacacionales', $this->rol($anterior, ['RolVacacionalFechaProgramada' => '2026-12-14', 'RolVacacionalDias' => 10]))->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalFechaProgramada']);
        $this->postJson('/api/roles-vacacionales', $this->rol($anterior, ['RolVacacionalFechaProgramada' => '2027-02-01', 'RolVacacionalDias' => 10]))->assertCreated();

        foreach (['CERRADO', 'ANULADO'] as $estado) {
            DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $anterior)->update(['PeriodoVacacionalEstado' => $estado]);
            $this->postJson('/api/roles-vacacionales', $this->rol($anterior, ['RolVacacionalFechaProgramada' => '2027-06-01', 'RolVacacionalDias' => 10]))
                ->assertStatus(422)->assertJsonValidationErrors(['PeriodoVacacionalId']);
        }
    }

    public function test_reprogramar_deja_la_programacion_actual_como_historia_y_crea_otra(): void
    {
        $periodo = $this->periodoVacacional($this->nuevoVinculo());
        $rol = $this->postJson('/api/roles-vacacionales', $this->rol($periodo))->assertCreated()->json('data.id');

        $nuevo = $this->postJson("/api/roles-vacacionales/{$rol}/reprogramar", ['RolVacacionalFechaProgramada' => '2027-01-11'])
            ->assertCreated()->assertJsonPath('data.estado', 'PROGRAMADO')->assertJsonPath('data.dias', 15)->assertJsonPath('data.fecha_fin_programada', '2027-01-25')->json('data.id');
        $this->assertNotSame($rol, $nuevo);
        $this->getJson("/api/roles-vacacionales/{$rol}")->assertOk()->assertJsonPath('data.estado', 'REPROGRAMADO')->assertJsonPath('data.activo', true);

        // La reprogramada es historia: no se modifica, no se reprograma ni se anula; la nueva si.
        $this->patchJson("/api/roles-vacacionales/{$rol}", ['RolVacacionalDias' => 10])->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalEstado']);
        $this->postJson("/api/roles-vacacionales/{$rol}/reprogramar", ['RolVacacionalFechaProgramada' => '2027-02-01'])->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalFechaProgramada']);
        $this->deleteJson("/api/roles-vacacionales/{$rol}")->assertStatus(422);
        $this->postJson("/api/roles-vacacionales/{$nuevo}/reprogramar", ['RolVacacionalFechaProgramada' => '2027-01-11'])->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalFechaProgramada']);
        $this->postJson("/api/roles-vacacionales/{$nuevo}/reprogramar", [])->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalFechaProgramada']);
        $this->deleteJson("/api/roles-vacacionales/{$nuevo}")->assertOk();
        $this->deleteJson("/api/roles-vacacionales/{$nuevo}")->assertOk();   // anular es idempotente
    }

    public function test_con_goces_solicitados_no_se_modifica_reprograma_ni_anula_la_programacion(): void
    {
        $periodo = $this->periodoVacacional($this->nuevoVinculo());
        $rol = $this->rolVacacional($periodo);
        $goce = $this->postJson('/api/goces-vacacionales', ['RolVacacionalId' => $rol, 'GoceVacacionalFechaInicio' => '2026-12-07', 'GoceVacacionalFechaFin' => '2026-12-13'])->assertCreated()->json('data.id');

        $this->patchJson("/api/roles-vacacionales/{$rol}", ['RolVacacionalDias' => 20])->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalEstado']);
        $this->postJson("/api/roles-vacacionales/{$rol}/reprogramar", ['RolVacacionalFechaProgramada' => '2027-01-11'])->assertStatus(422);
        $this->deleteJson("/api/roles-vacacionales/{$rol}")->assertStatus(422);

        $this->deleteJson("/api/goces-vacacionales/{$goce}")->assertOk();
        $this->deleteJson("/api/roles-vacacionales/{$rol}")->assertOk();
        $this->assertSame('ANULADO', DB::table('Vacaciones.RolVacacional')->where('RolVacacionalId', $rol)->value('RolVacacionalEstado'));
    }

    public function test_filtros_y_datos_sembrados_del_rol_vacacional(): void
    {
        $this->getJson('/api/roles-vacacionales?por_pagina=100')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/roles-vacacionales?estado=GOZADO')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/roles-vacacionales?estado=REPROGRAMADO')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/roles-vacacionales?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/roles-vacacionales?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/roles-vacacionales?anio=2025')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/roles-vacacionales?desde=2027-01-01&hasta=2027-12-31')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/roles-vacacionales?buscar=Quispe&estado=PROGRAMADO')->assertOk()->assertJsonCount(2, 'data');

        // Los dias gozados de cada periodo explican sus dias disponibles.
        foreach (DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalEstado', '<>', 'ANULADO')->get() as $p) {
            $gozados = DB::table('Vacaciones.GoceVacacional as g')->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')
                ->where('r.PeriodoVacacionalId', $p->PeriodoVacacionalId)->where('g.GoceVacacionalEstado', 'APROBADO')->sum('g.GoceVacacionalDias');
            $this->assertEquals($p->PeriodoVacacionalDiasGanados - $gozados, $p->PeriodoVacacionalDiasDisponibles, "Periodo vacacional {$p->PeriodoVacacionalId}");
        }
    }
}
