<?php

namespace Tests\Feature;

use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel3Modulos;

/**
 * Nivel 3, lote D: consolidado de asistencia, compensacion horaria, periodo vacacional, expediente PAD y supervision
 * inopinada (RIT, Art. 16, 17, 23, 24, 29, 68 y 100 a 107).
 */
class Nivel3ConsolidacionTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel3Modulos::loteD();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    private function usuario(string $nombre = 'pgutierrez'): int
    {
        return $this->id('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    private function periodo(int $mes): int
    {
        return $this->id('Consolidacion.PeriodoAsistencia', 'PeriodoAsistenciaId', ['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => $mes]);
    }

    private function estado(string $codigo): int
    {
        return $this->id('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', ['EstadoAsistenciaCodigo' => $codigo]);
    }

    private function dia(int $vinculo, string $fecha, string $estado, array $extra = []): void
    {
        DB::table('Asistencia.AsistenciaDiaria')->insert($extra + ['VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado($estado), 'AsistenciaDiariaFecha' => $fecha]);
    }

    // ================================================================== Consolidado de asistencia

    public function test_generar_calcula_el_consolidado_desde_la_asistencia_diaria(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->dia($vinculo, '2026-09-21', 'ASISTIO', ['AsistenciaDiariaMinutosExtra' => 30]);
        $this->dia($vinculo, '2026-09-22', 'ASISTIO');
        $this->dia($vinculo, '2026-09-23', 'TARDANZA', ['AsistenciaDiariaMinutosTardanza' => 11]);
        $this->dia($vinculo, '2026-09-24', 'FALTA');
        $this->dia($vinculo, '2026-09-25', 'FALTA_JUST');
        $this->dia($vinculo, '2026-09-26', 'DESCANSO');
        $this->dia($vinculo, '2026-08-31', 'ASISTIO');   // otro periodo: no cuenta
        $setiembre = $this->periodo(9);

        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])
            ->assertOk()->assertJsonPath('generados', 1)->assertJsonPath('actualizados', 0)->assertJsonPath('omitidos', 0);

        $c = $this->getJson("/api/consolidados-asistencia?periodo_asistencia_id={$setiembre}&vinculo_laboral_id={$vinculo}")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertEquals(3, $c['dias_trabajados']);          // 2 asistencias + 1 tardanza (el descanso no es dia laborable)
        $this->assertEquals(1, $c['dias_falta']);
        $this->assertEquals(1, $c['dias_falta_justificada']);
        $this->assertSame(11, $c['minutos_tardanza']);
        $this->assertSame(30, $c['minutos_extra']);
        $this->assertSame('GENERADO', $c['estado']);

        // Regenerar actualiza (con la asistencia nueva) y no duplica.
        $this->dia($vinculo, '2026-09-27', 'ASISTIO');
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])->assertOk()->assertJsonPath('generados', 0)->assertJsonPath('actualizados', 1);
        $this->assertEquals(4, $this->getJson("/api/consolidados-asistencia/{$c['id']}")->json('data.dias_trabajados'));
        // Conforme: ya no se regenera.
        $this->patchJson("/api/consolidados-asistencia/{$c['id']}", ['ConsolidadoAsistenciaEstado' => 'CONFORME'])->assertOk();
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'VinculoLaboralId' => $vinculo])->assertOk()->assertJsonPath('omitidos', 1)->assertJsonPath('actualizados', 0);
    }

    public function test_generar_por_establecimiento_y_validaciones_del_proceso(): void
    {
        $setiembre = $this->periodo(9);
        // Los 12 consolidados de la asistencia sembrada ya existen: solo se actualizan (los conformes se omiten).
        $r = $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre])->assertOk();
        $this->assertSame(0, $r->json('generados'));
        $this->assertSame(11, $r->json('actualizados'));
        $this->assertSame(1, $r->json('omitidos'));   // VL-0001 conforme

        $eess = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']);
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $setiembre, 'EessId' => $eess])->assertOk();

        $this->postJson('/api/consolidados-asistencia/generar', [])->assertStatus(422)->assertJsonValidationErrors(['PeriodoAsistenciaId']);
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => 999999])->assertStatus(422);
        // Un periodo cerrado ya no se genera.
        $this->postJson('/api/consolidados-asistencia/generar', ['PeriodoAsistenciaId' => $this->periodo(8)])->assertStatus(422);
    }

    public function test_reglas_del_consolidado_manual(): void
    {
        $octubre = $this->periodo(10);
        $vinculo = $this->nuevoVinculo();
        $base = ['PeriodoAsistenciaId' => $octubre, 'VinculoLaboralId' => $vinculo];

        // Periodo cerrado, vinculo que aun no existia, dias de mas.
        $this->postJson('/api/consolidados-asistencia', ['PeriodoAsistenciaId' => $this->periodo(8)] + $base)->assertStatus(422)->assertJsonValidationErrors(['PeriodoAsistenciaId']);
        $this->postJson('/api/consolidados-asistencia', ['VinculoLaboralId' => $this->nuevoVinculo(['VinculoLaboralFechaInicio' => '2026-11-15'])] + $base)->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
        $febrero = $this->periodo(2);
        DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaId', $febrero)->update(['PeriodoAsistenciaEstado' => 'ABIERTO']);
        $this->postJson('/api/consolidados-asistencia', ['PeriodoAsistenciaId' => $febrero, 'ConsolidadoAsistenciaDiasTrabajados' => 29] + $base)->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaDiasTrabajados']);

        $id = $this->postJson('/api/consolidados-asistencia', $base)->assertCreated()->assertJsonPath('data.estado', 'GENERADO')->assertJsonPath('data.periodo.mes', 10)->json('data.id');
        $this->postJson('/api/consolidados-asistencia', $base)->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);

        // Conforme: solo se puede cambiar el estado; no se elimina.
        $this->patchJson("/api/consolidados-asistencia/{$id}", ['ConsolidadoAsistenciaEstado' => 'CONFORME'])->assertOk();
        $this->patchJson("/api/consolidados-asistencia/{$id}", ['ConsolidadoAsistenciaDiasFalta' => 3])->assertStatus(422)->assertJsonValidationErrors(['ConsolidadoAsistenciaEstado']);
        $this->deleteJson("/api/consolidados-asistencia/{$id}")->assertStatus(422);
        $this->patchJson("/api/consolidados-asistencia/{$id}", ['ConsolidadoAsistenciaEstado' => 'OBSERVADO'])->assertOk();
        $this->deleteJson("/api/consolidados-asistencia/{$id}")->assertOk();
    }

    public function test_filtros_y_datos_sembrados_de_consolidados(): void
    {
        $this->getJson('/api/consolidados-asistencia?por_pagina=100')->assertOk()->assertJsonCount(16, 'data');
        $this->getJson('/api/consolidados-asistencia?estado=CERRADO')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/consolidados-asistencia?estado=OBSERVADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.minutos_tardanza', 11);
        $this->getJson('/api/consolidados-asistencia?periodo_asistencia_id='.$this->periodo(8))->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/consolidados-asistencia?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/consolidados-asistencia?eess_id='.$this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']).'&por_pagina=100')->assertOk();
    }

    // ================================================================== Compensacion horaria

    private function compensacion(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'TipoCompensacionId' => $this->id('Compensaciones.TipoCompensacion', 'TipoCompensacionId', ['TipoCompensacionCodigo' => 'HORA_EXTRA']),
            'CompensacionHorariaHorasGeneradas' => 2, 'CompensacionHorariaAutorizadoPreviamente' => true,
        ];
    }

    public function test_el_sobretiempo_es_de_una_hora_como_minimo_y_se_compensa_hasta_el_mes_siguiente(): void
    {
        $vinculo = $this->nuevoVinculo();

        // RIT Art. 17: una hora diaria como minimo (para el sobretiempo).
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaHorasGeneradas' => 0.5]))->assertStatus(422)->assertJsonValidationErrors(['CompensacionHorariaHorasGeneradas']);
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaHorasGeneradas' => 1]))->assertCreated()->assertJsonPath('data.horas_pendientes', 1);
        // Otros tipos (p. ej. permiso por compensar) no tienen ese minimo.
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['TipoCompensacionId' => $this->id('Compensaciones.TipoCompensacion', 'TipoCompensacionId', ['TipoCompensacionCodigo' => 'PERMISO_COMP']), 'CompensacionHorariaHorasGeneradas' => 0.5]))->assertCreated();

        // Fecha limite: hoy hasta el fin del mes siguiente (hoy es 2026-10-02 -> 2026-11-30).
        $hoy = now()->toDateString();
        $finMesSiguiente = date('Y-m-t', strtotime(date('Y-m-01').' +1 month'));
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaFechaLimite' => date('Y-m-d', strtotime($finMesSiguiente.' +1 day'))]))->assertStatus(422)->assertJsonValidationErrors(['CompensacionHorariaFechaLimite']);
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaFechaLimite' => date('Y-m-d', strtotime($hoy.' -1 day'))]))->assertStatus(422);
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaFechaLimite' => $finMesSiguiente]))->assertCreated();
    }

    public function test_no_procede_la_compensacion_sin_autorizacion_previa(): void
    {
        $vinculo = $this->nuevoVinculo();
        $sinAutorizar = $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaAutorizadoPreviamente' => false]))->assertCreated()->json('data.id');

        $this->postJson("/api/compensaciones-horarias/{$sinAutorizar}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->assertSame('PENDIENTE', DB::table('Compensaciones.CompensacionHoraria')->where('CompensacionHorariaId', $sinAutorizar)->value('CompensacionHorariaEstado'));
        // Con la autorizacion previa registrada, se aprueba y queda quien autoriza.
        $this->patchJson("/api/compensaciones-horarias/{$sinAutorizar}", ['CompensacionHorariaAutorizadoPreviamente' => true])->assertOk();
        $this->postJson("/api/compensaciones-horarias/{$sinAutorizar}/aprobar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'Cierre de planillas'])
            ->assertOk()->assertJsonPath('data.estado', 'APROBADO')->assertJsonPath('data.usuario_autorizacion.nombre', 'pgutierrez');
        $this->assertStringContainsString('Aprobación: Cierre de planillas', $this->getJson("/api/compensaciones-horarias/{$sinAutorizar}")->json('data.observacion'));
        // Aprobada: ya no se modifica ni se aprueba otra vez.
        $this->patchJson("/api/compensaciones-horarias/{$sinAutorizar}", ['CompensacionHorariaObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['CompensacionHorariaEstado']);
        $this->postJson("/api/compensaciones-horarias/{$sinAutorizar}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->postJson("/api/compensaciones-horarias/{$sinAutorizar}/aprobar", [])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
    }

    public function test_devolver_horas_hasta_consumir_la_compensacion(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['CompensacionHorariaHorasGeneradas' => 3]))->assertCreated()->json('data.id');

        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 1])->assertStatus(422);   // aun pendiente
        $this->postJson("/api/compensaciones-horarias/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertOk();

        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", [])->assertStatus(422)->assertJsonValidationErrors(['Horas']);
        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 0])->assertStatus(422);
        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 3.5])->assertStatus(422);
        $r = $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 1.5])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertEquals(1.5, $r->json('data.horas_devueltas'));
        $this->assertEquals(1.5, $r->json('data.horas_pendientes'));
        // Con horas devueltas ya no se anula.
        $this->deleteJson("/api/compensaciones-horarias/{$id}")->assertStatus(422);
        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 2])->assertStatus(422);
        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 1.5])->assertOk()->assertJsonPath('data.estado', 'CONSUMIDO')->assertJsonPath('data.horas_pendientes', 0);
        $this->postJson("/api/compensaciones-horarias/{$id}/devolver", ['Horas' => 1])->assertStatus(422);
    }

    public function test_anular_una_compensacion_y_la_asistencia_enlazada(): void
    {
        $vinculo = $this->nuevoVinculo();
        $otro = $this->nuevoVinculo();
        $this->dia($otro, '2026-09-21', 'ASISTIO');
        $ajena = $this->id('Asistencia.AsistenciaDiaria', 'AsistenciaDiariaId', ['VinculoLaboralId' => $otro]);
        $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['AsistenciaDiariaId' => $ajena]))->assertStatus(422)->assertJsonValidationErrors(['AsistenciaDiariaId']);

        $this->dia($vinculo, '2026-09-21', 'ASISTIO', ['AsistenciaDiariaMinutosExtra' => 120]);
        $propia = $this->id('Asistencia.AsistenciaDiaria', 'AsistenciaDiariaId', ['VinculoLaboralId' => $vinculo]);
        $id = $this->postJson('/api/compensaciones-horarias', $this->compensacion($vinculo, ['AsistenciaDiariaId' => $propia]))->assertCreated()->json('data.id');

        $this->deleteJson("/api/compensaciones-horarias/{$id}")->assertOk()->assertJsonPath('mensaje', 'Compensación anulada.');
        $this->getJson("/api/compensaciones-horarias/{$id}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
        $this->deleteJson("/api/compensaciones-horarias/{$id}")->assertOk();   // idempotente
    }

    public function test_filtros_y_datos_sembrados_de_compensaciones(): void
    {
        $this->getJson('/api/compensaciones-horarias?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/compensaciones-horarias?estado=APROBADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.horas_pendientes', 1)->assertJsonPath('data.0.vencida', false)
            ->assertJsonPath('data.0.usuario_autorizacion.nombre', 'gdangelo');
        $vencida = $this->getJson('/api/compensaciones-horarias?estado=VENCIDO')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertTrue($vencida['vencida']);
        $this->getJson('/api/compensaciones-horarias?tipo_compensacion_id='.$this->id('Compensaciones.TipoCompensacion', 'TipoCompensacionId', ['TipoCompensacionCodigo' => 'HORA_EXTRA']).'&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/compensaciones-horarias?buscar=planillas')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/compensaciones-horarias?desde=2026-09-01&hasta=2026-09-30&por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
    }

    // ================================================================== Periodo vacacional

    public function test_los_dias_ganados_son_como_maximo_treinta_por_anio(): void
    {
        $vinculo = $this->nuevoVinculo();
        $base = ['VinculoLaboralId' => $vinculo, 'PeriodoVacacionalAnio' => 2026, 'PeriodoVacacionalFechaInicio' => '2026-01-01', 'PeriodoVacacionalFechaFin' => '2026-12-31'];

        $r = $this->postJson('/api/periodos-vacacionales', $base)->assertCreated()->assertJsonPath('data.estado', 'ABIERTO');
        $this->assertEquals(30, $r->json('data.dias_ganados'));
        $this->assertEquals(30, $r->json('data.dias_disponibles'));   // aun no se goza ninguno

        $this->postJson('/api/periodos-vacacionales', ['PeriodoVacacionalAnio' => 2027, 'PeriodoVacacionalFechaInicio' => '2027-01-01', 'PeriodoVacacionalFechaFin' => '2027-12-31', 'PeriodoVacacionalDiasGanados' => 31] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['PeriodoVacacionalDiasGanados']);
        // Proporcional: 15 ganados con 15 disponibles; y disponibles no superan ganados.
        $r = $this->postJson('/api/periodos-vacacionales', ['PeriodoVacacionalAnio' => 2027, 'PeriodoVacacionalFechaInicio' => '2027-01-01', 'PeriodoVacacionalFechaFin' => '2027-12-31', 'PeriodoVacacionalDiasGanados' => 15] + $base)->assertCreated();
        $this->assertEquals(15, $r->json('data.dias_disponibles'));
        $this->postJson('/api/periodos-vacacionales', ['PeriodoVacacionalAnio' => 2028, 'PeriodoVacacionalFechaInicio' => '2028-01-01', 'PeriodoVacacionalFechaFin' => '2028-12-31', 'PeriodoVacacionalDiasGanados' => 15, 'PeriodoVacacionalDiasDisponibles' => 16] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['PeriodoVacacionalDiasDisponibles']);
    }

    public function test_estados_del_periodo_vacacional(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/periodos-vacacionales', ['VinculoLaboralId' => $vinculo, 'PeriodoVacacionalAnio' => 2026, 'PeriodoVacacionalFechaInicio' => '2026-01-01', 'PeriodoVacacionalFechaFin' => '2026-12-31'])->assertCreated()->json('data.id');

        $this->patchJson("/api/periodos-vacacionales/{$id}", ['PeriodoVacacionalDiasDisponibles' => 12])->assertOk();
        $this->assertEquals(12, $this->getJson("/api/periodos-vacacionales/{$id}")->json('data.dias_disponibles'));
        $this->patchJson("/api/periodos-vacacionales/{$id}", ['PeriodoVacacionalEstado' => 'CERRADO'])->assertOk()->assertJsonPath('data.estado', 'CERRADO');
        // Cerrado: no se modifica; si se puede reabrir cambiando solo el estado.
        $this->patchJson("/api/periodos-vacacionales/{$id}", ['PeriodoVacacionalDiasDisponibles' => 5])->assertStatus(422)->assertJsonValidationErrors(['PeriodoVacacionalEstado']);
        $this->patchJson("/api/periodos-vacacionales/{$id}", ['PeriodoVacacionalEstado' => 'ABIERTO'])->assertOk()->assertJsonPath('data.estado', 'ABIERTO');

        $this->deleteJson("/api/periodos-vacacionales/{$id}")->assertOk()->assertJsonPath('mensaje', 'Período vacacional anulado.');
        $this->patchJson("/api/periodos-vacacionales/{$id}", ['PeriodoVacacionalEstado' => 'ABIERTO'])->assertStatus(422);
        $this->getJson("/api/periodos-vacacionales/{$id}")->assertJsonPath('data.activo', false);
    }

    public function test_filtros_y_datos_sembrados_de_vacaciones(): void
    {
        $this->getJson('/api/periodos-vacacionales?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/periodos-vacacionales?anio=2026&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/periodos-vacacionales?estado=CERRADO')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/periodos-vacacionales?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, DB::table('Vacaciones.PeriodoVacacional')->whereRaw('PeriodoVacacionalDiasDisponibles > PeriodoVacacionalDiasGanados')->count());
    }

    // ================================================================== Expediente PAD

    private function pad(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'TipoFaltaDisciplinariaId' => $this->id('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaId', ['TipoFaltaDisciplinariaCodigo' => 'ABANDONO']),
            'ExpedientePadFechaInicio' => '2026-09-21',
        ];
    }

    public function test_el_procedimiento_disciplinario_avanza_y_termina(): void
    {
        $id = $this->postJson('/api/expedientes-pad', $this->pad($this->nuevoVinculo()))->assertCreated()->assertJsonPath('data.estado', 'INICIADO')->json('data.id');

        // No se salta la etapa de tramite ni se retrocede.
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO', 'ExpedientePadFechaFin' => '2026-10-01', 'ExpedientePadSancion' => 'Amonestación escrita'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadEstado']);
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'EN_PROCESO'])->assertOk()->assertJsonPath('data.estado', 'EN_PROCESO');
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'INICIADO'])->assertStatus(422);

        // Resolver exige la fecha de fin y una sancion de la Ley 30057.
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadFechaFin', 'ExpedientePadSancion']);
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO', 'ExpedientePadFechaFin' => '2026-09-01', 'ExpedientePadSancion' => 'Amonestación escrita'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadFechaFin']);
        // RIT Art. 101: la amonestacion verbal no pasa por el PAD; la sancion debe ser una de la ley.
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO', 'ExpedientePadFechaFin' => '2026-10-01', 'ExpedientePadSancion' => 'Amonestación verbal'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadSancion']);
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO', 'ExpedientePadFechaFin' => '2026-10-01', 'ExpedientePadSancion' => 'Descuento de un día'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadSancion']);
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadEstado' => 'RESUELTO', 'ExpedientePadFechaFin' => '2026-10-01', 'ExpedientePadSancion' => 'Suspensión sin goce de remuneraciones por 10 días'])
            ->assertOk()->assertJsonPath('data.estado', 'RESUELTO')->assertJsonPath('data.sancion', 'Suspensión sin goce de remuneraciones por 10 días');

        // Terminado: no se modifica ni se anula.
        $this->patchJson("/api/expedientes-pad/{$id}", ['ExpedientePadDescripcion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadEstado']);
        $this->deleteJson("/api/expedientes-pad/{$id}")->assertStatus(422);
    }

    public function test_archivar_anular_y_numero_unico(): void
    {
        $vinculo = $this->nuevoVinculo();
        $archivado = $this->postJson('/api/expedientes-pad', $this->pad($vinculo, ['ExpedientePadNumero' => 'ZZ-1']))->assertCreated()->json('data.id');
        $this->postJson('/api/expedientes-pad', $this->pad($vinculo, ['ExpedientePadNumero' => 'ZZ-1']))->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadNumero']);

        // Archivar (liminar, sin sancion) exige la fecha de fin.
        $this->patchJson("/api/expedientes-pad/{$archivado}", ['ExpedientePadEstado' => 'ARCHIVADO'])->assertStatus(422)->assertJsonValidationErrors(['ExpedientePadFechaFin']);
        $this->patchJson("/api/expedientes-pad/{$archivado}", ['ExpedientePadEstado' => 'ARCHIVADO', 'ExpedientePadFechaFin' => '2026-09-30'])->assertOk()->assertJsonPath('data.estado', 'ARCHIVADO');
        $this->deleteJson("/api/expedientes-pad/{$archivado}")->assertStatus(422);

        $abierto = $this->postJson('/api/expedientes-pad', $this->pad($vinculo, ['ExpedientePadNumero' => 'ZZ-2']))->assertCreated()->json('data.id');
        $this->deleteJson("/api/expedientes-pad/{$abierto}")->assertOk()->assertJsonPath('mensaje', 'Expediente anulado.');
        $this->getJson("/api/expedientes-pad/{$abierto}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
        $this->deleteJson("/api/expedientes-pad/{$abierto}")->assertOk();   // idempotente
        $this->postJson('/api/expedientes-pad', $this->pad($vinculo, ['ExpedientePadEstado' => 'EN_PROCESO']))->assertStatus(422);
    }

    public function test_filtros_y_datos_sembrados_de_expedientes(): void
    {
        $this->getJson('/api/expedientes-pad?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/expedientes-pad?estado=RESUELTO')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/expedientes-pad?buscar=PAD-2026-004')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tipo_falta.gravedad', 'GRAVE')->assertJsonPath('data.0.sancion', 'Suspensión sin goce de remuneraciones por 15 días');
        $this->getJson('/api/expedientes-pad?tipo_falta_disciplinaria_id='.$this->id('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaId', ['TipoFaltaDisciplinariaCodigo' => 'ABANDONO']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/expedientes-pad?desde=2026-09-01&hasta=2026-09-30')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, DB::table('Disciplina.ExpedientePad')->whereIn('ExpedientePadEstado', ['RESUELTO', 'ARCHIVADO'])->whereNull('ExpedientePadFechaFin')->count());
    }

    // ================================================================== Supervision inopinada

    public function test_el_trabajador_supervisado_trabaja_en_el_establecimiento(): void
    {
        $eessLe01 = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']);
        $enOtro = $this->nuevoVinculo(['EessId' => $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-EP-01'])]);
        $propio = $this->nuevoVinculo();
        $base = ['EessId' => $eessLe01, 'UsuarioId' => $this->usuario()];

        $this->postJson('/api/supervisiones-inopinadas', $base + ['VinculoLaboralId' => $enOtro])->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);
        $this->postJson('/api/supervisiones-inopinadas', $base + ['VinculoLaboralId' => $propio, 'SupervisionInopinadaFechaHora' => '2026-09-21T10:30'])
            ->assertCreated()->assertJsonPath('data.fecha_hora', '2026-09-21 10:30:00')->assertJsonPath('data.trabajador.id', (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $propio)->value('TrabajadorId'));
        // Supervision general del establecimiento (sin persona) y fecha por omision.
        $r = $this->postJson('/api/supervisiones-inopinadas', $base)->assertCreated()->assertJsonPath('data.estado', 'REGISTRADO');
        $this->assertStringStartsWith(HoraLocal::hoy()->format('Y-m-d'), $r->json('data.fecha_hora'));
    }

    public function test_estados_de_la_supervision(): void
    {
        $id = $this->postJson('/api/supervisiones-inopinadas', ['EessId' => $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']), 'UsuarioId' => $this->usuario()])->assertCreated()->json('data.id');

        $this->patchJson("/api/supervisiones-inopinadas/{$id}", ['SupervisionInopinadaEstado' => 'OBSERVADO'])->assertStatus(422)->assertJsonValidationErrors(['SupervisionInopinadaObservacion']);
        $this->patchJson("/api/supervisiones-inopinadas/{$id}", ['SupervisionInopinadaEstado' => 'OBSERVADO', 'SupervisionInopinadaObservacion' => 'Dos servidores ausentes'])->assertOk()->assertJsonPath('data.estado', 'OBSERVADO');
        $this->patchJson("/api/supervisiones-inopinadas/{$id}", ['SupervisionInopinadaEstado' => 'CONFORME'])->assertOk()->assertJsonPath('data.estado', 'CONFORME');
        $this->deleteJson("/api/supervisiones-inopinadas/{$id}")->assertOk()->assertJsonPath('mensaje', 'Supervisión anulada.');
        $this->patchJson("/api/supervisiones-inopinadas/{$id}", ['SupervisionInopinadaResultado' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['SupervisionInopinadaEstado']);
        $this->postJson('/api/supervisiones-inopinadas', ['EessId' => $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']), 'UsuarioId' => $this->usuario(), 'SupervisionInopinadaEstado' => 'CONFORME'])->assertStatus(422);
    }

    public function test_filtros_y_datos_sembrados_de_supervisiones(): void
    {
        $this->getJson('/api/supervisiones-inopinadas?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/supervisiones-inopinadas?estado=OBSERVADO')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/supervisiones-inopinadas?eess_id='.$this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'SEDE-RRHH']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.usuario.nombre', 'pgutierrez');
        $this->getJson('/api/supervisiones-inopinadas?buscar=papeleta')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/supervisiones-inopinadas?desde=2026-09-20&hasta=2026-09-30&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/supervisiones-inopinadas?usuario_id='.$this->usuario('gdangelo').'&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
    }
}
