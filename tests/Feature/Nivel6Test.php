<?php

namespace Tests\Feature;

use App\Services\CambioTurnoService;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel4Modulos;

/**
 * Nivel 6: cambio de turno sobre una programacion publicada (RIT, Art. 16 y 20): reemplazo, permuta, reprogramacion y
 * anulacion; peticion con 48 horas de anticipacion, tope de cuatro cambios por mes, reglas de la guardia y aplicacion del
 * cambio a la programacion al aprobarlo.
 */
class Nivel6Test extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel4Modulos::nivel6();
    }

    private function tipo(string $codigo): int
    {
        return $this->idPorCodigo('Programacion.TipoCambioTurno', 'TipoCambioTurnoId', 'TipoCambioTurnoCodigo', $codigo);
    }

    /** Solicitud de reemplazo del turno del escenario (manana del 10 de noviembre) por el otro enfermero. */
    private function reemplazo(array $extra = []): array
    {
        $e = $this->escenarioDeCambioDeTurno();

        return $extra + [
            'TipoCambioTurnoId' => $this->tipo('REEMPLAZO'), 'TurnoProgramadoId' => $e['turno'], 'VinculoLaboralSolicitanteId' => $e['solicitante'],
            'VinculoLaboralReemplazanteId' => $e['reemplazante'], 'UsuarioRegistroId' => $this->usuarioId(),
        ];
    }

    private function resolutor(array $extra = []): array
    {
        return $extra + ['UsuarioId' => $this->usuarioId('pgutierrez')];
    }

    private function turnoDeLaBase(int $turno): object
    {
        return DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $turno)->first();
    }

    /** Avisos de cambio de turno aprobado que recibio quien registra las solicitudes (los sembrados ya traen dos). */
    private function avisosDeAprobacion(): int
    {
        return DB::table('Soporte.Notificacion')->where('UsuarioId', $this->usuarioId())->where('NotificacionTipo', 'SOLICITUD_DE_CAMBIO_DE_TURNO_APROBADA')->count();
    }

    private function horas(int $programacionTrabajador): float
    {
        return (float) DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $programacionTrabajador)->value('ProgramacionTrabajadorHorasProgramadas');
    }

    // ================================================================== Aplicar el cambio al aprobarlo

    public function test_el_reemplazo_aprobado_pasa_el_turno_al_reemplazante_y_avisa_a_quien_lo_registro(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $id = $this->postJson('/api/cambios-turno', $this->reemplazo(['CambioTurnoMotivo' => 'Cita médica']))->assertCreated()
            ->assertJsonPath('data.estado', 'PENDIENTE')->assertJsonPath('data.usuario_aprobacion_id', null)->assertJsonPath('data.tipo.codigo', 'REEMPLAZO')
            ->assertJsonPath('data.turno_programado.fecha', '2026-11-10')->assertJsonPath('data.turno_programado.hora_entrada', '07:30')
            ->assertJsonPath('data.solicitante.vinculo_id', $e['solicitante'])->assertJsonPath('data.reemplazante.vinculo_id', $e['reemplazante'])->json('data.id');
        $this->assertNotNull($this->getJson("/api/cambios-turno/{$id}")->json('data.fecha_solicitud'));
        // Pendiente: la programacion no se toca.
        $this->assertEquals($e['programacionSolicitante'], $this->turnoDeLaBase($e['turno'])->ProgramacionTrabajadorId);

        $avisosAntes = $this->avisosDeAprobacion();
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor(['Motivo' => 'Autorizado por la jefatura']))->assertOk()
            ->assertJsonPath('data.estado', 'APROBADO')->assertJsonPath('data.usuario_aprobacion.nombre', 'pgutierrez');
        $this->assertNotNull($this->getJson("/api/cambios-turno/{$id}")->json('data.fecha_resolucion'));

        $turno = $this->turnoDeLaBase($e['turno']);
        $this->assertEquals($e['programacionReemplazante'], $turno->ProgramacionTrabajadorId);
        $this->assertSame('REPROGRAMADO', $turno->TurnoProgramadoEstado);
        $this->assertEquals(0, $this->horas($e['programacionSolicitante']));
        $this->assertEquals(6, $this->horas($e['programacionReemplazante']));
        $this->assertSame($avisosAntes + 1, $this->avisosDeAprobacion());
        // Aprobada: ya no se modifica, ni se aprueba de nuevo, ni se anula (ya se aplico).
        $this->patchJson("/api/cambios-turno/{$id}", ['CambioTurnoObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['CambioTurnoEstado']);
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertStatus(422);
        $this->deleteJson("/api/cambios-turno/{$id}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya se aplicó'));
    }

    public function test_el_reemplazante_que_aun_no_figuraba_en_la_programacion_se_incorpora_al_aprobar(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $nuevo = $this->nuevoVinculoDeGuardia();
        $id = $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralReemplazanteId' => $nuevo]))->assertCreated()->json('data.id');

        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertOk();
        $programacion = DB::table('Programacion.ProgramacionTrabajador')->where(['ProgramacionPeriodoId' => $e['periodo'], 'VinculoLaboralId' => $nuevo])->first();
        $this->assertNotNull($programacion);
        $this->assertSame('PUBLICADA', $programacion->ProgramacionTrabajadorEstado);
        $this->assertEquals($programacion->ProgramacionTrabajadorId, $this->turnoDeLaBase($e['turno'])->ProgramacionTrabajadorId);
    }

    public function test_la_permuta_aprobada_intercambia_los_turnos_de_los_dos_trabajadores(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $contraparte = $this->turnoProgramado($e['programacionReemplazante'], '2026-11-12', 'T');
        $datos = $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('PERMUTA'), 'TurnoProgramadoContraparteId' => $contraparte]);

        $id = $this->postJson('/api/cambios-turno', $datos)->assertCreated()->assertJsonPath('data.contraparte.fecha', '2026-11-12')->assertJsonPath('data.contraparte.turno.codigo', 'T')->json('data.id');
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertOk();

        $this->assertEquals($e['programacionReemplazante'], $this->turnoDeLaBase($e['turno'])->ProgramacionTrabajadorId);
        $this->assertEquals($e['programacionSolicitante'], $this->turnoDeLaBase($contraparte)->ProgramacionTrabajadorId);
        $this->assertSame(['REPROGRAMADO', 'REPROGRAMADO'], [$this->turnoDeLaBase($e['turno'])->TurnoProgramadoEstado, $this->turnoDeLaBase($contraparte)->TurnoProgramadoEstado]);
        $this->assertEquals(6, $this->horas($e['programacionSolicitante']));
        $this->assertEquals(6, $this->horas($e['programacionReemplazante']));
    }

    public function test_la_permuta_se_hace_con_el_turno_del_otro_trabajador_dentro_de_la_misma_programacion(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $propio = $this->turnoProgramado($e['programacionSolicitante'], '2026-11-12', 'T');
        $otraProgramacion = $this->programacionDeTrabajador($this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', '2026-11-20', '2026-11-25', 'EXTRAORD'), $e['reemplazante']);
        $deOtraProgramacion = $this->turnoProgramado($otraProgramacion, '2026-11-21', 'T');
        $base = $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('PERMUTA')]);

        $this->postJson('/api/cambios-turno', $base + ['TurnoProgramadoContraparteId' => $propio])->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoContraparteId']);   // no es del otro trabajador
        $this->postJson('/api/cambios-turno', $base + ['TurnoProgramadoContraparteId' => $deOtraProgramacion])->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoContraparteId']);   // otra programacion
        $this->postJson('/api/cambios-turno', $base)->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoContraparteId']);
    }

    public function test_la_reprogramacion_y_la_anulacion_modifican_el_turno_sin_reemplazante(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $reprogramacion = ['TipoCambioTurnoId' => $this->tipo('REPROGRAMACION'), 'TurnoIdNuevo' => $this->turnoId('T'), 'VinculoLaboralReemplazanteId' => null];

        // Sin reemplazante solo procede de manera excepcional: justificado y con la autorizacion escrita del jefe (RIT, Art. 20).
        $this->postJson('/api/cambios-turno', $this->reemplazo($reprogramacion))->assertStatus(422)->assertJsonValidationErrors(['CambioTurnoMotivo', 'DocumentoSustentoId']);
        $id = $this->postJson('/api/cambios-turno', $this->reemplazo($reprogramacion + ['CambioTurnoMotivo' => 'Trámite judicial en la mañana', 'DocumentoSustentoId' => $this->documentoId()]))
            ->assertCreated()->assertJsonPath('data.turno_nuevo.codigo', 'T')->json('data.id');
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertOk();
        $turno = $this->turnoDeLaBase($e['turno']);
        $this->assertEquals($this->turnoId('T'), $turno->TurnoId);
        $this->assertSame('REPROGRAMADO', $turno->TurnoProgramadoEstado);
        $this->assertEquals($e['programacionSolicitante'], $turno->ProgramacionTrabajadorId);

        // Anulacion: el turno queda sin efecto y deja de sumar horas.
        $anulacion = $this->postJson('/api/cambios-turno', $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('ANULACION'), 'VinculoLaboralReemplazanteId' => null,
            'CambioTurnoMotivo' => 'Descanso médico', 'DocumentoSustentoId' => $this->documentoId()]))->assertCreated()->json('data.id');
        $this->postJson("/api/cambios-turno/{$anulacion}/aprobar", $this->resolutor())->assertOk();
        $this->assertSame('ANULADO', $this->turnoDeLaBase($e['turno'])->TurnoProgramadoEstado);
        $this->assertEquals(0, $this->horas($e['programacionSolicitante']));
    }

    // ================================================================== Campos segun el tipo

    public function test_cada_tipo_exige_o_prohibe_sus_campos(): void
    {
        $this->escenarioDeCambioDeTurno();
        $turnoNuevo = $this->turnoId('T');
        $otroTurno = $this->turnoProgramado($this->escenarioDeCambioDeTurno()['programacionReemplazante'], '2026-11-12', 'T');
        $conSustento = ['CambioTurnoMotivo' => 'Necesidad del servicio', 'DocumentoSustentoId' => $this->documentoId()];

        $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralReemplazanteId' => null]))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralReemplazanteId']);
        $this->postJson('/api/cambios-turno', $this->reemplazo(['TurnoProgramadoContraparteId' => $otroTurno]))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoContraparteId']);
        $this->postJson('/api/cambios-turno', $this->reemplazo(['TurnoIdNuevo' => $turnoNuevo]))->assertStatus(422)->assertJsonValidationErrors(['TurnoIdNuevo']);
        $this->postJson('/api/cambios-turno', $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('REPROGRAMACION'), 'TurnoIdNuevo' => $turnoNuevo] + $conSustento))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralReemplazanteId']);
        $this->postJson('/api/cambios-turno', $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('REPROGRAMACION'), 'VinculoLaboralReemplazanteId' => null] + $conSustento))->assertStatus(422)->assertJsonValidationErrors(['TurnoIdNuevo']);
        // El mismo turno que ya tiene no es una reprogramacion.
        $this->postJson('/api/cambios-turno', $this->reemplazo(['TipoCambioTurnoId' => $this->tipo('REPROGRAMACION'), 'VinculoLaboralReemplazanteId' => null, 'TurnoIdNuevo' => $this->turnoId('M')] + $conSustento))
            ->assertStatus(422)->assertJsonValidationErrors(['TurnoIdNuevo']);
        // El solicitante debe ser a quien se programo el turno.
        $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralSolicitanteId' => $this->escenarioDeCambioDeTurno()['reemplazante'], 'VinculoLaboralReemplazanteId' => $this->escenarioDeCambioDeTurno()['solicitante']]))
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralSolicitanteId']);
    }

    public function test_un_tipo_de_cambio_sin_procedimiento_no_se_acepta(): void
    {
        $this->escenarioDeCambioDeTurno();
        $traslado = DB::table('Programacion.TipoCambioTurno')->insertGetId(['TipoCambioTurnoCodigo' => 'TRASLADO', 'TipoCambioTurnoNombre' => 'Traslado'], 'TipoCambioTurnoId');

        $this->postJson('/api/cambios-turno', $this->reemplazo(['TipoCambioTurnoId' => $traslado]))->assertStatus(422)->assertJsonValidationErrors(['TipoCambioTurnoId']);
    }

    // ================================================================== Quien reemplaza

    public function test_el_reemplazante_es_otra_persona_del_establecimiento_con_vinculo_vigente_y_la_agenda_libre(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $trabajadorSolicitante = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $e['solicitante'])->value('TrabajadorId');

        // La misma persona con otro vinculo no es "otro" trabajador.
        $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralReemplazanteId' => $this->nuevoVinculoDeGuardia([], $trabajadorSolicitante)]))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralReemplazanteId']);
        // De otro establecimiento, o con un vinculo que aun no habia empezado.
        $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralReemplazanteId' => $this->nuevoVinculoDeGuardia(['EessId' => $this->eessId('EESS-LE-02')])]))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralReemplazanteId']);
        $this->postJson('/api/cambios-turno', $this->reemplazo(['VinculoLaboralReemplazanteId' => $this->nuevoVinculoDeGuardia(['VinculoLaboralFechaInicio' => '2026-12-01'])]))->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralReemplazanteId']);

        // Con un turno que se le superpone ese dia, o que le sumaria 24 horas continuas.
        $this->turnoProgramado($e['programacionReemplazante'], '2026-11-10', 'M', ['TurnoProgramadoHoraEntrada' => '10:00:00', 'TurnoProgramadoHoraSalida' => '14:00:00']);
        $this->postJson('/api/cambios-turno', $this->reemplazo())->assertStatus(422)->assertJsonPath('errors.VinculoLaboralReemplazanteId.0', fn ($m) => str_contains($m, 'superpone'));
    }

    public function test_el_cambio_de_una_guardia_es_entre_servidores_del_mismo_cargo_y_regimen_dl_276_o_serums(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $guardia = $this->turnoProgramado($e['programacionSolicitante'], '2026-11-14', 'G12-D');
        $datos = $this->reemplazo(['TurnoProgramadoId' => $guardia]);

        // Mismo cargo y regimen que el titular: procede.
        $this->postJson('/api/cambios-turno', $datos)->assertCreated();

        // Otro cargo (RIT, Art. 20, inciso j), o un regimen que no hace guardia.
        $otroCargo = $this->nuevoVinculoDeGuardia(['CargoId' => $this->idPorCodigo('Personal.Cargo', 'CargoId', 'CargoNombre', 'Contador(a)')]);
        $this->postJson('/api/cambios-turno', ['VinculoLaboralReemplazanteId' => $otroCargo] + $datos)->assertStatus(422)
            ->assertJsonPath('errors.VinculoLaboralReemplazanteId.0', fn ($m) => str_contains($m, 'mismo cargo'));
        $sinGuardia = $this->nuevoVinculo(['CargoId' => $this->idPorCodigo('Personal.Cargo', 'CargoId', 'CargoNombre', 'Enfermero(a)')]);
        $this->postJson('/api/cambios-turno', ['VinculoLaboralReemplazanteId' => $sinGuardia] + $datos)->assertStatus(422)
            ->assertJsonPath('errors.VinculoLaboralReemplazanteId.0', fn ($m) => str_contains($m, 'D.L. 276'));
    }

    // ================================================================== Plazo, tope mensual y estado de la programacion

    public function test_la_peticion_se_hace_con_48_horas_de_anticipacion_o_con_el_documento_que_sustenta_la_excepcion(): void
    {
        $hoy = HoraLocal::hoy();
        $solicitante = $this->nuevoVinculoDeGuardia();
        $reemplazante = $this->nuevoVinculoDeGuardia();
        $periodo = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-01', $hoy->subDays(3)->toDateString(), $hoy->addDays(20)->toDateString(), 'EXTRAORD');
        $pt = $this->programacionDeTrabajador($periodo, $solicitante);
        $this->programacionDeTrabajador($periodo, $reemplazante);
        $datos = fn (string $fecha) => [
            'TipoCambioTurnoId' => $this->tipo('REEMPLAZO'), 'TurnoProgramadoId' => $this->turnoProgramado($pt, $fecha), 'VinculoLaboralSolicitanteId' => $solicitante,
            'VinculoLaboralReemplazanteId' => $reemplazante, 'UsuarioRegistroId' => $this->usuarioId(),
        ];

        // Un turno que ya empezo no se cambia; con menos de 48 horas solo con documento; con mas, sin mas.
        $this->postJson('/api/cambios-turno', $datos($hoy->subDay()->toDateString()))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoId']);
        $manana = $datos($hoy->addDay()->toDateString());
        $this->postJson('/api/cambios-turno', $manana)->assertStatus(422)->assertJsonValidationErrors(['DocumentoSustentoId']);
        $this->postJson('/api/cambios-turno', $manana + ['DocumentoSustentoId' => $this->documentoId()])->assertCreated();
        $this->postJson('/api/cambios-turno', $datos($hoy->addDays(5)->toDateString()))->assertCreated();
    }

    public function test_cada_servidor_acepta_hasta_cuatro_cambios_al_mes_y_la_guardia_cuenta_como_dos(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $pedir = fn (int $turno, array $extra = []) => $this->postJson('/api/cambios-turno', $this->reemplazo(['TurnoProgramadoId' => $turno] + $extra));
        $turnos = array_map(fn (string $dia) => $this->turnoProgramado($e['programacionSolicitante'], "2026-11-{$dia}"), ['11', '12', '13', '16', '17']);

        $ids = [];
        foreach (array_slice($turnos, 0, 3) as $turno) {
            $ids[] = $pedir($turno)->assertCreated()->json('data.id');
        }
        // Una guardia valdria dos: 3 + 2 pasa de 4 (RIT, Art. 20, inciso b).
        $guardia = $this->turnoProgramado($e['programacionSolicitante'], '2026-11-20', 'G12-D');
        $pedir($guardia)->assertStatus(422)->assertJsonPath('errors.VinculoLaboralSolicitanteId.0', fn ($m) => str_contains($m, '3 de 4') && str_contains($m, 'guardia'));
        // El cuarto cambio ordinario si cabe; el quinto no, y tampoco lo acepta el reemplazante (el tope es de cada servidor).
        $cuarto = $pedir($turnos[3])->assertCreated()->json('data.id');
        $pedir($turnos[4])->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralSolicitanteId', 'VinculoLaboralReemplazanteId']);

        // Un cambio rechazado o anulado ya no cuenta.
        $this->postJson("/api/cambios-turno/{$cuarto}/rechazar", $this->resolutor(['Motivo' => 'No hay quien cubra']))->assertOk();
        $this->deleteJson("/api/cambios-turno/{$ids[0]}")->assertOk();
        $pedir($turnos[4])->assertCreated();
        $pedir($guardia)->assertStatus(422);   // 3 pendientes (dos + el quinto) + 2 = 5
    }

    public function test_solo_se_cambian_turnos_pendientes_de_una_programacion_publicada_y_de_un_periodo_abierto(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $turno = $e['turno'];
        $estadoPeriodo = fn (string $estado) => DB::table('Programacion.ProgramacionPeriodo')->where('ProgramacionPeriodoId', $e['periodo'])->update(['ProgramacionPeriodoEstado' => $estado]);

        foreach (['BORRADOR', 'CERRADA', 'ANULADA'] as $estado) {
            $estadoPeriodo($estado);
            $this->postJson('/api/cambios-turno', $this->reemplazo())->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoId']);
        }
        $estadoPeriodo('PUBLICADA');
        foreach (['CUMPLIDO', 'ANULADO'] as $estado) {
            DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $turno)->update(['TurnoProgramadoEstado' => $estado]);
            $this->postJson('/api/cambios-turno', $this->reemplazo())->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoId']);
        }
        DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $turno)->update(['TurnoProgramadoEstado' => 'PROGRAMADO']);
        $this->postJson('/api/cambios-turno', $this->reemplazo())->assertCreated();
    }

    // ================================================================== Resolver

    public function test_aprobar_verifica_de_nuevo_y_si_ya_no_procede_no_cambia_nada(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $id = $this->postJson('/api/cambios-turno', $this->reemplazo())->assertCreated()->json('data.id');

        // Mientras estaba pendiente, el reemplazante tomo otro turno que choca.
        $this->turnoProgramado($e['programacionReemplazante'], '2026-11-10', 'M', ['TurnoProgramadoHoraEntrada' => '09:00:00', 'TurnoProgramadoHoraSalida' => '12:00:00']);
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'superpone'));
        $this->assertSame('PENDIENTE', DB::table('Programacion.CambioTurno')->where('CambioTurnoId', $id)->value('CambioTurnoEstado'));
        $this->assertEquals($e['programacionSolicitante'], $this->turnoDeLaBase($e['turno'])->ProgramacionTrabajadorId);
        $this->assertSame('PROGRAMADO', $this->turnoDeLaBase($e['turno'])->TurnoProgramadoEstado);

        // Y si la programacion se cerro o el turno se anulo, tampoco.
        DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $e['turno'])->update(['TurnoProgramadoEstado' => 'ANULADO']);
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertStatus(422);
        $this->postJson("/api/cambios-turno/{$id}/aprobar", [])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
    }

    public function test_rechazar_pide_motivo_y_deja_la_programacion_igual(): void
    {
        $e = $this->escenarioDeCambioDeTurno();
        $id = $this->postJson('/api/cambios-turno', $this->reemplazo())->assertCreated()->json('data.id');

        $this->postJson("/api/cambios-turno/{$id}/rechazar", $this->resolutor())->assertStatus(422)->assertJsonValidationErrors(['Motivo']);
        $this->postJson("/api/cambios-turno/{$id}/rechazar", $this->resolutor(['Motivo' => 'No hay quien cubra']))->assertOk()
            ->assertJsonPath('data.estado', 'RECHAZADO')->assertJsonPath('data.observacion', fn ($o) => str_contains($o, 'No hay quien cubra'));
        $this->assertEquals($e['programacionSolicitante'], $this->turnoDeLaBase($e['turno'])->ProgramacionTrabajadorId);
        $this->postJson("/api/cambios-turno/{$id}/aprobar", $this->resolutor())->assertStatus(422);
        $this->patchJson("/api/cambios-turno/{$id}", ['CambioTurnoMotivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['CambioTurnoEstado']);
        // Resuelta la solicitud, el turno admite otra.
        $this->postJson('/api/cambios-turno', $this->reemplazo())->assertCreated();
    }

    // ================================================================== Datos sembrados

    public function test_filtros_y_datos_sembrados_de_los_cambios_de_turno(): void
    {
        $this->getJson('/api/cambios-turno?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/cambios-turno?estado=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/cambios-turno?estado=APROBADO')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/cambios-turno?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/cambios-turno?tipo_cambio_turno_id='.$this->tipo('REEMPLAZO'))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/cambios-turno?eess_id='.$this->eessId('EESS-LE-01'))->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/cambios-turno?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/cambios-turno?buscar=Castillo')->assertOk()->assertJsonCount(0, 'data');
        $vinculo = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', 'VL-0016')->value('VinculoLaboralId');
        $this->getJson("/api/cambios-turno?vinculo_laboral_id={$vinculo}&por_pagina=100")->assertOk()->assertJsonCount(4, 'data');   // como solicitante o como reemplazante

        // La permuta aprobada ya se aplico: cada turno esta con el otro trabajador y REPROGRAMADO.
        $permuta = DB::table('Programacion.CambioTurno')->where(['TipoCambioTurnoId' => $this->tipo('PERMUTA'), 'CambioTurnoEstado' => 'APROBADO'])->first();
        $this->assertNotNull($permuta->UsuarioAprobacionId);
        $this->assertNotNull($permuta->CambioTurnoFechaResolucion);
        $propio = $this->turnoDeLaBase($permuta->TurnoProgramadoId);
        $contraparte = $this->turnoDeLaBase($permuta->TurnoProgramadoContraparteId);
        $this->assertEquals($permuta->VinculoLaboralReemplazanteId, DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $propio->ProgramacionTrabajadorId)->value('VinculoLaboralId'));
        $this->assertEquals($permuta->VinculoLaboralSolicitanteId, DB::table('Programacion.ProgramacionTrabajador')->where('ProgramacionTrabajadorId', $contraparte->ProgramacionTrabajadorId)->value('VinculoLaboralId'));
        $this->assertSame('REPROGRAMADO', $propio->TurnoProgramadoEstado);
        // La anulacion aprobada dejo el turno ANULADO; los rechazados y pendientes no tocaron la programacion.
        $anulacion = DB::table('Programacion.CambioTurno')->where(['TipoCambioTurnoId' => $this->tipo('ANULACION')])->first();
        $this->assertSame('ANULADO', $this->turnoDeLaBase($anulacion->TurnoProgramadoId)->TurnoProgramadoEstado);
        $rechazado = DB::table('Programacion.CambioTurno')->where('CambioTurnoEstado', 'RECHAZADO')->first();
        $this->assertSame('PROGRAMADO', $this->turnoDeLaBase($rechazado->TurnoProgramadoId)->TurnoProgramadoEstado);
        // Ningun solicitante ni reemplazante pasa de cuatro cambios en el mes.
        $servicio = app(CambioTurnoService::class);
        foreach (DB::table('Personal.VinculoLaboral')->whereIn('VinculoLaboralCodigo', ['VL-0001', 'VL-0015', 'VL-0016'])->pluck('TrabajadorId') as $trabajador) {
            $this->assertLessThanOrEqual(4, $servicio->cambiosDelMes($trabajador, '2026-10-15'));
        }
    }
}
