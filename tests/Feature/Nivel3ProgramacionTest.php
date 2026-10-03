<?php

namespace Tests\Feature;

use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel3Modulos;

/**
 * Nivel 3, lote C: programacion por periodo, carga de programacion, informe de guardia comunitaria, asignacion de
 * horario, responsables de EESS, roles y ambitos de usuario, y sesiones de acceso (solo lectura).
 */
class Nivel3ProgramacionTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel3Modulos::loteC();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    private function eess(string $codigo): int
    {
        return $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => $codigo]);
    }

    private function tipoPeriodo(string $codigo): int
    {
        return $this->id('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionId', ['TipoPeriodoProgramacionCodigo' => $codigo]);
    }

    private function usuario(string $nombre = 'rvargas'): int
    {
        return $this->id('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    private function horario(string $codigo): int
    {
        return $this->id('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => $codigo]);
    }

    private function programacion(string $tipo, array $extra = []): array
    {
        return $extra + [
            'EessId' => $this->eess('EESS-LE-02'), 'TipoPeriodoProgramacionId' => $this->tipoPeriodo($tipo), 'UsuarioRegistroId' => $this->usuario(),
            'ProgramacionPeriodoAnio' => 2026, 'ProgramacionPeriodoMes' => 11,
            'ProgramacionPeriodoFechaInicio' => '2026-11-01', 'ProgramacionPeriodoFechaFin' => '2026-11-30',
        ];
    }

    // ================================================================== Programacion por periodo

    public function test_las_fechas_deben_ser_coherentes_con_el_tipo_de_periodo(): void
    {
        // MENSUAL: el mes completo.
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['ProgramacionPeriodoFechaFin' => '2026-11-29']))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoFechaInicio']);
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['ProgramacionPeriodoMes' => null]))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoMes']);
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL'))->assertCreated()->assertJsonPath('data.estado', 'BORRADOR')->assertJsonPath('data.tipo_periodo.codigo', 'MENSUAL');

        // QUINCENAL: indica la quincena y sus fechas (1 al 15, 16 al fin de mes).
        $quincena = fn (array $extra) => $this->programacion('QUINCENAL', ['EessId' => $this->eess('EESS-LE-03')] + $extra);
        $this->postJson('/api/programaciones-periodo', $quincena(['ProgramacionPeriodoFechaFin' => '2026-11-15']))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoNumero']);
        $this->postJson('/api/programaciones-periodo', $quincena(['ProgramacionPeriodoNumero' => 1]))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoFechaInicio']);
        $this->postJson('/api/programaciones-periodo', $quincena(['ProgramacionPeriodoNumero' => 1, 'ProgramacionPeriodoFechaFin' => '2026-11-15']))->assertCreated();
        $this->postJson('/api/programaciones-periodo', $quincena(['ProgramacionPeriodoNumero' => 2, 'ProgramacionPeriodoFechaInicio' => '2026-11-16', 'ProgramacionPeriodoFechaFin' => '2026-11-30']))->assertCreated();
        $this->postJson('/api/programaciones-periodo', $quincena(['ProgramacionPeriodoNumero' => 2, 'ProgramacionPeriodoFechaInicio' => '2026-12-16', 'ProgramacionPeriodoFechaFin' => '2026-12-30', 'ProgramacionPeriodoMes' => 12]))->assertStatus(422);

        // SEMANAL: hasta 7 dias y sin cruzar de mes; EXTRAORD: libre.
        $semana = fn (array $extra) => $this->programacion('SEMANAL', ['EessId' => $this->eess('EESS-EP-03'), 'ProgramacionPeriodoNumero' => 1] + $extra);
        $this->postJson('/api/programaciones-periodo', $semana(['ProgramacionPeriodoFechaFin' => '2026-11-10']))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoFechaFin']);
        $this->postJson('/api/programaciones-periodo', $semana(['ProgramacionPeriodoFechaFin' => '2026-11-07']))->assertCreated();
        $this->postJson('/api/programaciones-periodo', $this->programacion('EXTRAORD', ['EessId' => $this->eess('EESS-EP-03'), 'ProgramacionPeriodoMes' => null, 'ProgramacionPeriodoFechaInicio' => '2026-11-20', 'ProgramacionPeriodoFechaFin' => '2026-11-25']))->assertCreated();
    }

    public function test_no_se_superponen_programaciones_del_mismo_establecimiento_y_tipo(): void
    {
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL'))->assertCreated();
        $this->postJson('/api/programaciones-periodo', $this->programacion('EXTRAORD', ['ProgramacionPeriodoMes' => null, 'ProgramacionPeriodoFechaInicio' => '2026-11-10', 'ProgramacionPeriodoFechaFin' => '2026-11-12']))->assertCreated();   // otro tipo
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['EessId' => $this->eess('EESS-LE-03')]))->assertCreated();   // otro establecimiento
        // Otro mes no choca; el mismo mes si.
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['ProgramacionPeriodoMes' => 12, 'ProgramacionPeriodoFechaInicio' => '2026-12-01', 'ProgramacionPeriodoFechaFin' => '2026-12-31']))->assertCreated();
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL'))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoFechaInicio']);
    }

    public function test_publicar_cerrar_y_anular_una_programacion(): void
    {
        $id = $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL'))->assertCreated()->json('data.id');

        $this->postJson("/api/programaciones-periodo/{$id}/cerrar")->assertStatus(422);   // aun es borrador
        $r = $this->postJson("/api/programaciones-periodo/{$id}/publicar")->assertOk()->assertJsonPath('data.estado', 'PUBLICADA');
        $this->assertNotNull($r->json('data.fecha_publicacion'));
        $this->postJson("/api/programaciones-periodo/{$id}/publicar")->assertStatus(422);

        // RIT Art. 16: remitida la programacion, ya no se modifica.
        $this->patchJson("/api/programaciones-periodo/{$id}", ['ProgramacionPeriodoObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoEstado']);

        $this->postJson("/api/programaciones-periodo/{$id}/cerrar")->assertOk()->assertJsonPath('data.estado', 'CERRADA');
        $this->deleteJson("/api/programaciones-periodo/{$id}")->assertStatus(422);   // una cerrada no se anula
        $this->postJson('/api/programaciones-periodo/999999/publicar')->assertNotFound();

        $otra = $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['ProgramacionPeriodoMes' => 12, 'ProgramacionPeriodoFechaInicio' => '2026-12-01', 'ProgramacionPeriodoFechaFin' => '2026-12-31']))->json('data.id');
        $this->deleteJson("/api/programaciones-periodo/{$otra}")->assertOk()->assertJsonPath('mensaje', 'Programación anulada.');
        $this->getJson("/api/programaciones-periodo/{$otra}")->assertJsonPath('data.estado', 'ANULADA')->assertJsonPath('data.activo', false);
        $this->deleteJson("/api/programaciones-periodo/{$otra}")->assertOk();   // idempotente
        // Una anulada libera el periodo para otras fechas, pero la restriccion de la base (establecimiento, tipo e inicio) sigue
        // contando: se avisa con un 422 y no con un 500.
        $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL', ['ProgramacionPeriodoMes' => 12, 'ProgramacionPeriodoFechaInicio' => '2026-12-01', 'ProgramacionPeriodoFechaFin' => '2026-12-31']))
            ->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoFechaInicio']);
    }

    public function test_filtros_y_datos_sembrados_de_programaciones(): void
    {
        $this->getJson('/api/programaciones-periodo?por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/programaciones-periodo?estado=BORRADOR')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/programaciones-periodo?estado=PUBLICADA')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/programaciones-periodo?buscar=PGM-LE-2026-10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.eess.codigo', 'EESS-LE-01');
        $this->getJson('/api/programaciones-periodo?eess_id='.$this->eess('EESS-EP-01'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/programaciones-periodo?anio=2026&mes=9')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'CERRADA');
        $this->getJson('/api/programaciones-periodo?tipo_periodo_programacion_id='.$this->tipoPeriodo('QUINCENAL'))->assertOk()->assertJsonCount(2, 'data');
    }

    // ================================================================== Carga de programacion

    public function test_el_codigo_de_la_carga_se_genera_si_no_se_envia(): void
    {
        $base = [
            'EessId' => $this->eess('EESS-LE-02'), 'DocumentoSustentoId' => $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'citt-0002.pdf']),
            'UsuarioRegistroId' => $this->usuario(), 'CargaProgramacionAnio' => 2026, 'CargaProgramacionMes' => 11, 'CargaProgramacionFechaDocumento' => '2026-09-30',
        ];

        // Las cargas de octubre y setiembre sembradas no influyen en noviembre.
        $this->postJson('/api/cargas-programacion', $base)->assertCreated()->assertJsonPath('data.codigo', 'PROG-2026-11-001')->assertJsonPath('data.estado', 'REGISTRADO');
        $this->postJson('/api/cargas-programacion', $base)->assertCreated()->assertJsonPath('data.codigo', 'PROG-2026-11-002');
        $this->postJson('/api/cargas-programacion', ['CargaProgramacionMes' => 10] + $base)->assertCreated()->assertJsonPath('data.codigo', 'PROG-2026-10-004');
        $this->postJson('/api/cargas-programacion', $base + ['CargaProgramacionCodigo' => 'PROG-2026-11-001'])->assertStatus(422)->assertJsonValidationErrors(['CargaProgramacionCodigo']);
    }

    public function test_la_carga_quincenal_indica_la_quincena_y_el_enlace_es_coherente(): void
    {
        $eess = $this->eess('EESS-LE-02');
        $base = [
            'EessId' => $eess, 'DocumentoSustentoId' => $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'citt-0002.pdf']),
            'UsuarioRegistroId' => $this->usuario(), 'CargaProgramacionAnio' => 2026, 'CargaProgramacionMes' => 11, 'CargaProgramacionFechaDocumento' => '2026-09-30',
        ];
        $this->postJson('/api/cargas-programacion', $base + ['TipoPeriodoProgramacionId' => $this->tipoPeriodo('QUINCENAL')])->assertStatus(422)->assertJsonValidationErrors(['CargaProgramacionNumero']);
        $this->postJson('/api/cargas-programacion', $base + ['TipoPeriodoProgramacionId' => $this->tipoPeriodo('QUINCENAL'), 'CargaProgramacionNumero' => 2])->assertCreated();

        $propia = $this->postJson('/api/programaciones-periodo', $this->programacion('MENSUAL'))->assertCreated()->json('data.id');
        $this->postJson('/api/cargas-programacion', $base + ['ProgramacionPeriodoId' => $propia])->assertCreated()->assertJsonPath('data.programacion_periodo_id', $propia);
        // De otro mes, de otro establecimiento o anulada: no se enlaza.
        $this->postJson('/api/cargas-programacion', ['CargaProgramacionMes' => 10] + $base + ['ProgramacionPeriodoId' => $propia])->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoId']);
        $this->postJson('/api/cargas-programacion', ['EessId' => $this->eess('EESS-LE-03')] + $base + ['ProgramacionPeriodoId' => $propia])->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoId']);
        $this->deleteJson("/api/programaciones-periodo/{$propia}")->assertOk();
        $this->postJson('/api/cargas-programacion', $base + ['ProgramacionPeriodoId' => $propia])->assertStatus(422)->assertJsonValidationErrors(['ProgramacionPeriodoId']);
    }

    public function test_estados_de_la_carga_de_programacion(): void
    {
        $id = $this->postJson('/api/cargas-programacion', [
            'EessId' => $this->eess('EESS-LE-02'), 'DocumentoSustentoId' => $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'citt-0002.pdf']),
            'UsuarioRegistroId' => $this->usuario(), 'CargaProgramacionAnio' => 2026, 'CargaProgramacionMes' => 11, 'CargaProgramacionFechaDocumento' => '2026-09-30',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/cargas-programacion/{$id}", ['CargaProgramacionEstado' => 'OBSERVADO'])->assertStatus(422)->assertJsonValidationErrors(['CargaProgramacionObservacion']);
        $this->patchJson("/api/cargas-programacion/{$id}", ['CargaProgramacionEstado' => 'OBSERVADO', 'CargaProgramacionObservacion' => 'Falta el sello'])->assertOk()->assertJsonPath('data.estado', 'OBSERVADO');
        $this->patchJson("/api/cargas-programacion/{$id}", ['CargaProgramacionEstado' => 'CONFORME'])->assertOk()->assertJsonPath('data.estado', 'CONFORME');
        $this->deleteJson("/api/cargas-programacion/{$id}")->assertOk()->assertJsonPath('mensaje', 'Carga de programación anulada.');
        $this->patchJson("/api/cargas-programacion/{$id}", ['CargaProgramacionMotivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['CargaProgramacionEstado']);
        $this->getJson('/api/cargas-programacion?estado=ANULADO')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/cargas-programacion?buscar=OF-0345')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.programacion_periodo_id', $this->id('Programacion.ProgramacionPeriodo', 'ProgramacionPeriodoId', ['ProgramacionPeriodoCodigo' => 'PGM-LE-2026-10']));
        $this->getJson('/api/cargas-programacion?anio=2026&mes=10&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
    }

    // ================================================================== Informe de guardia comunitaria

    private function informe(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'InformeGuardiaComunitariaFecha' => '2026-09-20', 'InformeGuardiaComunitariaHoraInicio' => '08:00',
            'InformeGuardiaComunitariaHoraFin' => '20:00', 'InformeGuardiaComunitariaDescripcion' => 'Visitas domiciliarias',
        ];
    }

    public function test_la_guardia_comunitaria_la_hace_solo_personal_276_o_serums(): void
    {
        $otroRegimen = $this->nuevoVinculo();   // regimen OTRO
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($otroRegimen))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralId']);

        $dl276 = $this->nuevoVinculo(['RegimenLaboralId' => $this->id('Personal.RegimenLaboral', 'RegimenLaboralId', ['RegimenLaboralCodigo' => 'DL276'])]);
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($dl276))->assertCreated()->assertJsonPath('data.hora_inicio', '08:00')->assertJsonPath('data.estado', 'PENDIENTE');

        // SERUMS de presupuesto nacional tambien (RIT, Art. 16, SERUMS).
        $serums = $this->nuevoVinculo(['CondicionLaboralId' => $this->id('Personal.CondicionLaboral', 'CondicionLaboralId', ['CondicionLaboralCodigo' => 'SERUMS_REM'])]);
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($serums))->assertCreated();
    }

    public function test_la_guardia_comunitaria_dura_hasta_doce_horas_y_no_se_repite_el_dia(): void
    {
        $vinculo = $this->nuevoVinculo(['RegimenLaboralId' => $this->id('Personal.RegimenLaboral', 'RegimenLaboralId', ['RegimenLaboralCodigo' => 'DL276'])]);

        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo, ['InformeGuardiaComunitariaHoraFin' => '20:01']))->assertStatus(422)->assertJsonValidationErrors(['InformeGuardiaComunitariaHoraFin']);
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo, ['InformeGuardiaComunitariaHoraFin' => '08:00']))->assertStatus(422);
        $id = $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo))->assertCreated()->json('data.id');
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo))->assertStatus(422)->assertJsonValidationErrors(['InformeGuardiaComunitariaFecha']);
        // Rechazado: se puede volver a presentar.
        $this->postJson("/api/informes-guardia-comunitaria/{$id}/rechazar", ['UsuarioId' => $this->usuario('pgutierrez'), 'Motivo' => 'Sin visado'])->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo))->assertCreated();
        // Sin horas tambien se acepta (el informe lo exige el RIT; las horas son opcionales).
        $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo, ['InformeGuardiaComunitariaFecha' => '2026-09-21', 'InformeGuardiaComunitariaHoraInicio' => null, 'InformeGuardiaComunitariaHoraFin' => null]))->assertCreated();
    }

    public function test_aprobar_el_informe_y_filtros_sembrados(): void
    {
        $vinculo = $this->nuevoVinculo(['RegimenLaboralId' => $this->id('Personal.RegimenLaboral', 'RegimenLaboralId', ['RegimenLaboralCodigo' => 'DL276'])]);
        $id = $this->postJson('/api/informes-guardia-comunitaria', $this->informe($vinculo))->assertCreated()->json('data.id');

        $this->postJson("/api/informes-guardia-comunitaria/{$id}/aprobar", ['UsuarioId' => $this->usuario('pgutierrez')])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->patchJson("/api/informes-guardia-comunitaria/{$id}", ['InformeGuardiaComunitariaDescripcion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['InformeGuardiaComunitariaEstado']);
        $this->postJson("/api/informes-guardia-comunitaria/{$id}/aprobar", ['UsuarioId' => $this->usuario('pgutierrez')])->assertStatus(422);

        $this->getJson('/api/informes-guardia-comunitaria?por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/informes-guardia-comunitaria?estado=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/informes-guardia-comunitaria?buscar=vacunación')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/informes-guardia-comunitaria?desde=2026-09-01&hasta=2026-09-30&por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
    }

    // ================================================================== Asignacion de horario

    public function test_un_vinculo_tiene_un_solo_horario_vigente_y_se_conserva_el_historico(): void
    {
        $vinculo = $this->nuevoVinculo();
        $asignar = fn (string $horario, string $inicio, ?string $fin = null) => ['VinculoLaboralId' => $vinculo, 'HorarioId' => $this->horario($horario), 'AsignacionHorarioFechaInicio' => $inicio, 'AsignacionHorarioFechaFin' => $fin];

        $primera = $this->postJson('/api/asignaciones-horario', $asignar('HOR-ADM-LV', '2026-01-01'))->assertCreated()->assertJsonPath('data.activo', true)->json('data.id');
        // Otro horario mientras el primero sigue abierto: no.
        $this->postJson('/api/asignaciones-horario', $asignar('HOR-ESS-M', '2026-07-01'))->assertStatus(422)->assertJsonValidationErrors(['AsignacionHorarioFechaInicio']);
        // Se cierra la vigente y se crea la nueva a continuacion: historico.
        $this->patchJson("/api/asignaciones-horario/{$primera}", ['AsignacionHorarioFechaFin' => '2026-06-30'])->assertOk();
        $this->postJson('/api/asignaciones-horario', $asignar('HOR-ESS-M', '2026-07-01'))->assertCreated();
        $this->getJson("/api/asignaciones-horario?vinculo_laboral_id={$vinculo}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.horario.codigo', 'HOR-ESS-M');
        $this->getJson("/api/asignaciones-horario?vinculo_laboral_id={$vinculo}&vigente=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.horario.codigo', 'HOR-ESS-M');
        $this->getJson("/api/asignaciones-horario?vinculo_laboral_id={$vinculo}&vigente=0")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.horario.codigo', 'HOR-ADM-LV');

        // Desactivar la vigente libera el espacio; reactivar la antigua ahora choca.
        $this->postJson('/api/asignaciones-horario', $asignar('HOR-ESS-T', '2026-08-01'))->assertStatus(422);
        $segunda = $this->getJson("/api/asignaciones-horario?vinculo_laboral_id={$vinculo}&vigente=1")->json('data.0.id');
        $this->deleteJson("/api/asignaciones-horario/{$segunda}")->assertOk();
        $this->postJson('/api/asignaciones-horario', $asignar('HOR-ESS-T', '2026-08-01'))->assertCreated();
        $this->patchJson("/api/asignaciones-horario/{$segunda}", ['AsignacionHorarioEstado' => true])->assertStatus(422)->assertJsonValidationErrors(['AsignacionHorarioFechaInicio']);
    }

    public function test_un_horario_propio_de_un_establecimiento_solo_se_asigna_a_su_personal(): void
    {
        $vinculoLe01 = $this->nuevoVinculo();   // EESS-LE-01
        $enOtroEess = $this->nuevoVinculo(['EessId' => $this->eess('EESS-EP-01')]);
        $asignar = fn (int $v, string $horario) => ['VinculoLaboralId' => $v, 'HorarioId' => $this->horario($horario), 'AsignacionHorarioFechaInicio' => '2026-02-01'];

        $this->postJson('/api/asignaciones-horario', $asignar($enOtroEess, 'HOR-LE-ROT'))->assertStatus(422)->assertJsonValidationErrors(['HorarioId']);
        $this->postJson('/api/asignaciones-horario', $asignar($vinculoLe01, 'HOR-LE-ROT'))->assertCreated();
        // Los horarios de toda la Red se asignan a cualquiera; uno inactivo, a nadie.
        $this->postJson('/api/asignaciones-horario', $asignar($enOtroEess, 'HOR-ADM-LV'))->assertCreated();
        $this->postJson('/api/asignaciones-horario', $asignar($this->nuevoVinculo(), 'HOR-FM-OLD'))->assertStatus(422)->assertJsonValidationErrors(['HorarioId']);
    }

    public function test_datos_sembrados_de_asignaciones(): void
    {
        $this->getJson('/api/asignaciones-horario?por_pagina=100')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/asignaciones-horario?vigente=1&por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/asignaciones-horario?buscar=Rojas&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/asignaciones-horario?horario_id='.$this->horario('HOR-ADM-LV'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/asignaciones-horario?estado=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        // Ningun vinculo tiene dos horarios abiertos a la vez.
        $abiertos = DB::table('Personal.AsignacionHorario')->where('AsignacionHorarioEstado', 1)->whereNull('AsignacionHorarioFechaFin')
            ->selectRaw('VinculoLaboralId, COUNT(*) AS n')->groupBy('VinculoLaboralId')->get();
        $this->assertTrue($abiertos->every(fn ($fila) => (int) $fila->n === 1));
    }

    // ================================================================== Responsable de EESS

    public function test_un_solo_responsable_vigente_por_establecimiento_y_tipo(): void
    {
        $eess = $this->eess('EESS-LE-02');
        $jefe = $this->id('Organizacion.TipoResponsabilidad', 'TipoResponsabilidadId', ['TipoResponsabilidadCodigo' => 'JEFE_EESS']);
        $designar = fn (int $vinculo, string $inicio, ?string $fin = null, int $tipo = 0) => [
            'EessId' => $eess, 'VinculoLaboralId' => $vinculo, 'TipoResponsabilidadId' => $tipo ?: $jefe,
            'ResponsableEessFechaInicio' => $inicio, 'ResponsableEessFechaFin' => $fin,
        ];
        $a = $this->nuevoVinculo();
        $b = $this->nuevoVinculo();

        $primero = $this->postJson('/api/responsables-eess', $designar($a, '2026-01-01'))->assertCreated()->assertJsonPath('data.activo', true)->json('data.id');
        $this->postJson('/api/responsables-eess', $designar($b, '2026-06-01'))->assertStatus(422)->assertJsonValidationErrors(['TipoResponsabilidadId']);
        // Otro tipo de responsabilidad en el mismo EESS si.
        $this->postJson('/api/responsables-eess', $designar($b, '2026-06-01', null, $this->id('Organizacion.TipoResponsabilidad', 'TipoResponsabilidadId', ['TipoResponsabilidadCodigo' => 'RESP_PERSONAL'])))->assertCreated();
        // Cerrar al jefe y designar al siguiente.
        $this->patchJson("/api/responsables-eess/{$primero}", ['ResponsableEessFechaFin' => '2026-05-31'])->assertOk();
        $segundo = $this->postJson('/api/responsables-eess', $designar($b, '2026-06-01'))->assertCreated()->json('data.id');
        $this->getJson("/api/responsables-eess?eess_id={$eess}&tipo_responsabilidad_id={$jefe}&vigente=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $segundo);
        $this->getJson("/api/responsables-eess?eess_id={$eess}&tipo_responsabilidad_id={$jefe}&vigente=0")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $primero);
        // Reactivar una designacion desactivada que choca con la vigente, no.
        $this->deleteJson("/api/responsables-eess/{$segundo}")->assertOk();
        $tercero = $this->postJson('/api/responsables-eess', $designar($a, '2026-06-01'))->assertCreated()->json('data.id');
        $this->patchJson("/api/responsables-eess/{$segundo}", ['ResponsableEessEstado' => true])->assertStatus(422);
        $this->assertNotNull($tercero);
    }

    public function test_filtros_y_datos_sembrados_de_responsables(): void
    {
        $this->getJson('/api/responsables-eess?por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/responsables-eess?vigente=1&por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/responsables-eess?eess_id='.$this->eess('EESS-EP-01'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/responsables-eess?microred_id='.$this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-LE']).'&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/responsables-eess?buscar=RD-0012-2026')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tipo_responsabilidad.codigo', 'JEFE_EESS')->assertJsonPath('data.0.documento.nombre', 'resolucion-comision-0003.pdf');
        $this->getJson('/api/responsables-eess?estado=0')->assertOk()->assertJsonCount(1, 'data');
    }

    // ================================================================== Usuario - rol y ambito

    public function test_un_usuario_tiene_cada_rol_una_sola_vez_y_solo_si_esta_activo(): void
    {
        $usuario = $this->usuario('vsanchez');
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'RRHH_RED']);
        $id = $this->postJson('/api/usuarios-roles', ['UsuarioId' => $usuario, 'RolId' => $rol])->assertCreated()->json('data.id');
        $this->assertSame(HoraLocal::hoy()->toDateString(), $this->getJson("/api/usuarios-roles/{$id}")->json('data.fecha_inicio'));   // la base pone hoy

        $this->postJson('/api/usuarios-roles', ['UsuarioId' => $usuario, 'RolId' => $rol])->assertStatus(422)->assertJsonValidationErrors(['RolId']);
        // Desactivado, el rol no se vuelve a crear: se reactiva.
        $this->deleteJson("/api/usuarios-roles/{$id}")->assertOk();
        $this->postJson('/api/usuarios-roles', ['UsuarioId' => $usuario, 'RolId' => $rol])->assertStatus(422);
        $this->patchJson("/api/usuarios-roles/{$id}", ['UsuarioRolEstado' => true])->assertOk()->assertJsonPath('data.activo', true);
        // Usuario inactivo: no recibe roles.
        $this->postJson('/api/usuarios-roles', ['UsuarioId' => $this->usuario('jretirado'), 'RolId' => $rol])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);

        $this->getJson('/api/usuarios-roles?por_pagina=100')->assertOk()->assertJsonCount(12, 'data');
        $this->getJson('/api/usuarios-roles?buscar=pgutierrez')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/usuarios-roles?rol_id='.$this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'TRABAJADOR']).'&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/usuarios-roles?estado=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fecha_fin', '2024-06-30');
    }

    public function test_el_ambito_es_la_red_una_microred_o_un_establecimiento(): void
    {
        $usuario = $this->usuario('vsanchez');
        $microred = $this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-EP']);
        $eess = $this->eess('EESS-LE-02');

        $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario, 'MicroredId' => $microred, 'EessId' => $eess])->assertStatus(422)->assertJsonValidationErrors(['EessId']);
        $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario, 'MicroredId' => $microred])->assertCreated()->assertJsonPath('data.alcance', 'MICRORED')->assertJsonPath('data.microred.codigo', 'MR-EP');
        $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario, 'EessId' => $eess])->assertCreated()->assertJsonPath('data.alcance', 'EESS');
        $red = $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario])->assertCreated()->assertJsonPath('data.alcance', 'RED')->json('data.id');

        // Cada ambito una sola vez por usuario (incluida "toda la Red").
        $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
        $this->postJson('/api/usuarios-ambitos', ['UsuarioId' => $usuario, 'MicroredId' => $microred])->assertStatus(422);
        $this->patchJson("/api/usuarios-ambitos/{$red}", ['EessId' => $eess])->assertStatus(422);

        $this->getJson('/api/usuarios-ambitos?por_pagina=100')->assertOk()->assertJsonCount(11, 'data');
        $this->getJson('/api/usuarios-ambitos?buscar=Gutiérrez')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.alcance', 'RED');
        $this->getJson('/api/usuarios-ambitos?microred_id='.$this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-FM']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/usuarios-ambitos?estado=0')->assertOk()->assertJsonCount(1, 'data');
    }

    // ================================================================== Sesion de acceso (solo lectura)

    public function test_las_sesiones_de_acceso_son_de_solo_lectura(): void
    {
        $this->getJson('/api/sesiones-acceso?por_pagina=100')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonStructure(['data' => [['id', 'usuario_id', 'fecha_inicio', 'fecha_fin', 'direccion_ip', 'resultado', 'usuario']]]);
        $id = $this->getJson('/api/sesiones-acceso?buscar=192.168.20.14')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.usuario.nombre', 'mquispe')->json('data.0.id');
        $this->getJson("/api/sesiones-acceso/{$id}")->assertOk()->assertJsonPath('data.resultado', 'EXITOSO');
        $this->getJson('/api/sesiones-acceso?resultado=CLAVE_INCORRECTA')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/sesiones-acceso?usuario_id='.$this->usuario('pgutierrez'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/sesiones-acceso?desde=2026-10-01&hasta=2026-10-02')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/sesiones-acceso/999999')->assertNotFound();

        $this->postJson('/api/sesiones-acceso', ['UsuarioId' => $this->usuario()])->assertStatus(405);
        $this->patchJson("/api/sesiones-acceso/{$id}", ['SesionAccesoResultado' => 'x'])->assertStatus(405);
        $this->deleteJson("/api/sesiones-acceso/{$id}")->assertStatus(405);
    }
}
