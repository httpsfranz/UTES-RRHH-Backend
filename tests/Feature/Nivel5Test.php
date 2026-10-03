<?php

namespace Tests\Feature;

use App\Models\Programacion\TurnoProgramado;
use App\Services\TurnoProgramadoService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel4Modulos;

/**
 * Nivel 5: turnos programados y goces vacacionales (RIT, Art. 16, 20 y 68 a 74). Las lineas de la liquidacion, tambien de
 * este nivel, se prueban en Nivel4LiquidacionTest junto con su cabecera.
 */
class Nivel5Test extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel4Modulos::nivel5();
    }

    // ================================================================== Turno programado

    /** Programacion en borrador de noviembre con un enfermero del D.L. 276 (el que hace guardia). */
    private function borradorConGuardia(): array
    {
        $periodo = $this->periodoDeProgramacion('BORRADOR');
        $vinculo = $this->nuevoVinculoDeGuardia();

        return [$periodo, $vinculo, $this->programacionDeTrabajador($periodo, $vinculo)];
    }

    private function turno(int $programacion, string $fecha, string $codigo = 'M', array $extra = []): array
    {
        return ['ProgramacionTrabajadorId' => $programacion, 'TurnoId' => $this->turnoId($codigo), 'TurnoProgramadoFecha' => $fecha] + $extra;
    }

    public function test_el_turno_hereda_las_horas_del_turno_y_la_guardia_se_deduce_del_turno(): void
    {
        [, , $pt] = $this->borradorConGuardia();

        $manana = $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02'))->assertCreated()
            ->assertJsonPath('data.hora_entrada', null)->assertJsonPath('data.es_guardia', false)->assertJsonPath('data.duracion_minutos', 360)
            ->assertJsonPath('data.turno.hora_entrada', '07:30')->assertJsonPath('data.estado', 'PROGRAMADO')->json('data.id');
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-04', 'G12-D'))->assertCreated()->assertJsonPath('data.es_guardia', true)->assertJsonPath('data.duracion_minutos', 720);
        // Con horas propias (p. ej. una guardia que empieza mas tarde) se usan esas.
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-06', 'M', ['TurnoProgramadoHoraEntrada' => '08:00', 'TurnoProgramadoHoraSalida' => '14:00']))
            ->assertCreated()->assertJsonPath('data.hora_entrada', '08:00')->assertJsonPath('data.duracion_minutos', 360);
        $this->assertEquals(24, $this->getJson("/api/programaciones-trabajador/{$pt}")->json('data.horas_programadas'));

        $this->deleteJson("/api/turnos-programados/{$manana}")->assertOk();
        $this->assertEquals(18, $this->getJson("/api/programaciones-trabajador/{$pt}")->json('data.horas_programadas'));
    }

    public function test_la_guardia_la_hace_solo_el_personal_del_dl_276_y_el_serums(): void
    {
        $periodo = $this->periodoDeProgramacion('BORRADOR');
        $destacado = $this->programacionDeTrabajador($periodo, $this->nuevoVinculo());   // regimen "otro", condicion destacado
        $serums = $this->programacionDeTrabajador($periodo, $this->nuevoVinculo([
            'CondicionLaboralId' => $this->idPorCodigo('Personal.CondicionLaboral', 'CondicionLaboralId', 'CondicionLaboralCodigo', 'SERUMS_EQUIV'),
        ]));

        // RIT, Art. 20: un turno de guardia, o marcar un turno como guardia, solo para D.L. 276 y SERUMS.
        $this->postJson('/api/turnos-programados', $this->turno($destacado, '2026-11-04', 'G12-D'))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoEsGuardia']);
        $this->postJson('/api/turnos-programados', $this->turno($destacado, '2026-11-04', 'M', ['TurnoProgramadoEsGuardia' => true]))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoEsGuardia']);
        $this->postJson('/api/turnos-programados', $this->turno($destacado, '2026-11-04', 'M'))->assertCreated();
        $this->postJson('/api/turnos-programados', $this->turno($serums, '2026-11-04', 'N'))->assertCreated()->assertJsonPath('data.es_guardia', true);
    }

    public function test_un_turno_de_guardia_es_siempre_una_guardia_aunque_el_formulario_diga_que_no(): void
    {
        [, , $pt] = $this->borradorConGuardia();
        $destacado = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2027-01-01'), $this->nuevoVinculo());

        // El formulario envia false por omision: no puede dejar de ser guardia lo que el turno es.
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-04', 'G12-D', ['TurnoProgramadoEsGuardia' => false]))->assertCreated()->assertJsonPath('data.es_guardia', true);
        $this->postJson('/api/turnos-programados', $this->turno($destacado, '2027-01-05', 'G12-D', ['TurnoProgramadoEsGuardia' => false]))
            ->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoEsGuardia']);
        // Un turno ordinario puede declararse guardia (reten), no al reves.
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-06', 'M', ['TurnoProgramadoEsGuardia' => true]))->assertCreated()->assertJsonPath('data.es_guardia', true);
    }

    public function test_un_trabajador_no_esta_en_dos_turnos_a_la_vez_ni_acumula_24_horas_continuas(): void
    {
        [, $vinculo, $pt] = $this->borradorConGuardia();
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02', 'M'))->assertCreated();

        // Se superpone con la manana (07:30-13:30).
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02', 'T', ['TurnoProgramadoHoraEntrada' => '12:00', 'TurnoProgramadoHoraSalida' => '18:00']))
            ->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha'])->assertJsonPath('errors.TurnoProgramadoFecha.0', fn ($m) => str_contains($m, 'superpone'));
        // Que un turno empiece cuando termina el otro no es superposicion: manana + tarde (12 horas seguidas).
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02', 'T'))->assertCreated();
        // Con la guardia nocturna que sigue serian 24 horas continuas: prohibido (RIT, Art. 20).
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02', 'N'))->assertStatus(422)->assertJsonPath('errors.TurnoProgramadoFecha.0', fn ($m) => str_contains($m, '24 horas'));
        // El turno que cruza la medianoche choca con el del dia siguiente.
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-05', 'N'))->assertCreated();
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-06', 'M', ['TurnoProgramadoHoraEntrada' => '06:00', 'TurnoProgramadoHoraSalida' => '10:00']))
            ->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha']);

        // La agenda es de la persona, no del vinculo: con dos vinculos no puede estar en los dos turnos a la vez.
        $trabajador = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $vinculo)->value('TrabajadorId');
        $segundo = $this->nuevoVinculoDeGuardia([], $trabajador);
        $otraProgramacion = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-02', '2026-11-01'), $segundo);
        $this->postJson('/api/turnos-programados', $this->turno($otraProgramacion, '2026-11-02', 'M'))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha']);
    }

    public function test_solo_se_programa_en_un_borrador_dentro_de_su_periodo_y_con_vinculo_vigente(): void
    {
        [$periodo, , $pt] = $this->borradorConGuardia();

        // Una programacion publicada no se modifica (RIT, Art. 16): ni crear, ni editar, ni quitar turnos.
        $existente = $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-02'))->assertCreated()->json('data.id');
        $this->postJson("/api/programaciones-periodo/{$periodo}/publicar")->assertOk();
        $this->postJson('/api/turnos-programados', $this->turno($pt, '2026-11-03'))->assertStatus(422)->assertJsonValidationErrors(['ProgramacionTrabajadorId']);
        $this->patchJson("/api/turnos-programados/{$existente}", ['TurnoProgramadoObservacion' => 'Cambio'])->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha']);
        $this->deleteJson("/api/turnos-programados/{$existente}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'cambio de turno'));

        // El vinculo empezo despues de la fecha del turno.
        $tardio = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2027-01-01'), $this->nuevoVinculo(['VinculoLaboralFechaInicio' => '2027-01-10']));
        $this->postJson('/api/turnos-programados', $this->turno($tardio, '2027-01-05'))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha']);
        $this->postJson('/api/turnos-programados', $this->turno($tardio, '2027-01-12'))->assertCreated();

        // Un periodo de asistencia cerrado (agosto) es inmutable.
        $agosto = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-02', '2026-08-01'), $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]));
        $this->postJson('/api/turnos-programados', $this->turno($agosto, '2026-08-10'))->assertStatus(422)->assertJsonValidationErrors(['TurnoProgramadoFecha']);
    }

    public function test_cumplir_marca_el_turno_realizado_solo_en_una_programacion_publicada(): void
    {
        $periodo = $this->periodoDeProgramacion('PUBLICADA', 'EESS-LE-02', '2026-09-01');
        $pt = $this->programacionDeTrabajador($periodo, $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]));
        $pasado = $this->turnoProgramado($pt, '2026-09-10');
        $futuro = $this->turnoProgramado($pt, '2026-09-29', 'M', ['TurnoProgramadoEstado' => 'REPROGRAMADO']);
        DB::table('Programacion.TurnoProgramado')->where('TurnoProgramadoId', $futuro)->update(['TurnoProgramadoFecha' => '2027-01-10']);

        $this->postJson("/api/turnos-programados/{$pasado}/cumplir")->assertOk()->assertJsonPath('data.estado', 'CUMPLIDO');
        $this->postJson("/api/turnos-programados/{$pasado}/cumplir")->assertStatus(422);
        $this->postJson("/api/turnos-programados/{$futuro}/cumplir")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'aún no empieza'));

        $borrador = $this->programacionDeTrabajador($this->periodoDeProgramacion('BORRADOR', 'EESS-LE-02', '2026-07-01'), $this->nuevoVinculo(['EessId' => $this->eessId('EESS-LE-02')]));
        $this->postJson('/api/turnos-programados/'.$this->turnoProgramado($borrador, '2026-07-10').'/cumplir')->assertStatus(422);
    }

    public function test_filtros_y_datos_sembrados_de_los_turnos(): void
    {
        $this->getJson('/api/turnos-programados?por_pagina=100')->assertOk()->assertJsonCount(48, 'data');
        $this->getJson('/api/turnos-programados?estado=CUMPLIDO&por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/turnos-programados?estado=REPROGRAMADO')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/turnos-programados?estado=ANULADO')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/turnos-programados?es_guardia=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/turnos-programados?eess_id='.$this->eessId('EESS-LE-01').'&desde=2026-10-05&hasta=2026-10-09&por_pagina=100')->assertOk()->assertJsonCount(15, 'data');
        $this->getJson('/api/turnos-programados?buscar=Cabrera&por_pagina=100')->assertOk()->assertJsonCount(12, 'data');
        $this->getJson('/api/turnos-programados?turno_id='.$this->turnoId('N'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fecha', '2026-10-10');

        // Ningun trabajador sembrado esta en dos turnos a la vez, ni acumula 24 horas continuas.
        $servicio = app(TurnoProgramadoService::class);
        foreach (TurnoProgramado::query()->with(['turno', 'programacionTrabajador.vinculoLaboral'])->where('TurnoProgramadoEstado', '<>', 'ANULADO')->get() as $t) {
            $this->assertNull($servicio->conflictoDeAgenda($t->programacionTrabajador->vinculoLaboral->TrabajadorId, $t->TurnoProgramadoFecha->toDateString(),
                $t->horaEntradaEfectiva(), $t->horaSalidaEfectiva(), [$t->TurnoProgramadoId]), 'Turno '.$t->TurnoProgramadoId);
        }
    }

    // ================================================================== Goce vacacional

    /** Periodo vacacional (30 dias) con un descanso programado de 15 dias del 7 al 21 de diciembre. */
    private function descansoProgramado(): array
    {
        $vinculo = $this->nuevoVinculo();
        $periodo = $this->periodoVacacional($vinculo);

        return [$vinculo, $periodo, $this->rolVacacional($periodo, '2026-12-07', 15)];
    }

    private function goce(int $rol, string $inicio, string $fin, array $extra = []): array
    {
        return ['RolVacacionalId' => $rol, 'GoceVacacionalFechaInicio' => $inicio, 'GoceVacacionalFechaFin' => $fin] + $extra;
    }

    private function disponibles(int $periodo): float
    {
        return (float) DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $periodo)->value('PeriodoVacacionalDiasDisponibles');
    }

    private function estadoDelRol(int $rol): string
    {
        return DB::table('Vacaciones.RolVacacional')->where('RolVacacionalId', $rol)->value('RolVacacionalEstado');
    }

    public function test_aprobar_el_goce_descuenta_los_dias_y_el_descanso_queda_gozado_al_cubrirlo(): void
    {
        [, $periodo, $rol] = $this->descansoProgramado();

        $primero = $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertCreated()
            ->assertJsonPath('data.estado', 'PENDIENTE')->assertJsonPath('data.dias', 7)->json('data.id');
        $this->assertEquals(30, $this->disponibles($periodo));   // pendiente: aun no descuenta

        $this->postJson("/api/goces-vacacionales/{$primero}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertEquals(23, $this->disponibles($periodo));
        $this->assertSame('PROGRAMADO', $this->estadoDelRol($rol));   // faltan 8 dias del descanso
        $this->postJson("/api/goces-vacacionales/{$primero}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertStatus(422);

        // El resto del descanso: al cubrirlo todo, queda GOZADO.
        $segundo = $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-14', '2026-12-21'))->assertCreated()->json('data.id');
        $this->postJson("/api/goces-vacacionales/{$segundo}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertOk();
        $this->assertEquals(15, $this->disponibles($periodo));
        $this->assertSame('GOZADO', $this->estadoDelRol($rol));
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalId']);

        // Un goce aprobado que aun no empezo se puede anular: devuelve los dias y reabre la programacion.
        $this->deleteJson("/api/goces-vacacionales/{$segundo}")->assertOk();
        $this->assertEquals(23, $this->disponibles($periodo));
        $this->assertSame('PROGRAMADO', $this->estadoDelRol($rol));
        $this->getJson("/api/goces-vacacionales/{$segundo}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
    }

    public function test_un_goce_aprobado_que_ya_empezo_no_se_anula(): void
    {
        [, $periodo, $rol] = $this->descansoProgramado();
        $goce = (int) DB::table('Vacaciones.GoceVacacional')->insertGetId([
            'RolVacacionalId' => $rol, 'GoceVacacionalFechaInicio' => '2026-09-01', 'GoceVacacionalFechaFin' => '2026-09-07', 'GoceVacacionalDias' => 7, 'GoceVacacionalEstado' => 'APROBADO',
        ], 'GoceVacacionalId');

        $this->deleteJson("/api/goces-vacacionales/{$goce}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya empezó'));
        $this->assertSame('APROBADO', DB::table('Vacaciones.GoceVacacional')->where('GoceVacacionalId', $goce)->value('GoceVacacionalEstado'));
        $this->assertEquals(30, $this->disponibles($periodo));
    }

    public function test_el_goce_cae_dentro_de_lo_programado_sin_cruzarse_ni_pasar_los_dias(): void
    {
        [, , $rol] = $this->descansoProgramado();

        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-01', '2026-12-10'))->assertStatus(422)->assertJsonValidationErrors(['GoceVacacionalFechaInicio']);
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertCreated();
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-10', '2026-12-17'))->assertStatus(422)->assertJsonValidationErrors(['GoceVacacionalFechaInicio']);   // se cruza
        // Quedan 8 dias del descanso: 9 serian de mas.
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-14', '2026-12-22'))->assertStatus(422)->assertJsonValidationErrors(['GoceVacacionalFechaInicio']);
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-14', '2026-12-21'))->assertCreated();
    }

    public function test_un_goce_menor_de_siete_dias_es_un_fraccionamiento_y_exige_el_documento(): void
    {
        [, , $rol] = $this->descansoProgramado();

        // RIT, Art. 72 y 73: el fraccionamiento se solicita por escrito.
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-10'))->assertStatus(422)->assertJsonValidationErrors(['DocumentoSustentoId']);
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-10', ['DocumentoSustentoId' => $this->documentoId()]))
            ->assertCreated()->assertJsonPath('data.dias', 4)->assertJsonPath('data.documento.nombre', 'resolucion-comision-0003.pdf');
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-11', '2026-12-17'))->assertCreated();   // 7 dias: no se fracciona
    }

    public function test_no_se_otorga_vacaciones_a_quien_esta_incapacitado_ni_en_un_periodo_cerrado(): void
    {
        [$vinculo, $periodo, $rol] = $this->descansoProgramado();
        DB::table('Solicitudes.DescansoMedico')->insert([
            'VinculoLaboralId' => $vinculo, 'DescansoMedicoNumeroCitt' => 'CITT-0001', 'DescansoMedicoFechaInicio' => '2026-12-12', 'DescansoMedicoFechaFin' => '2026-12-14', 'DescansoMedicoEstado' => 'APROBADO',
        ]);

        // RIT, Art. 70: el descanso vacacional no se otorga a quien esta incapacitado por enfermedad o accidente.
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertStatus(422)->assertJsonPath('errors.GoceVacacionalFechaInicio.0', fn ($m) => str_contains($m, 'incapacitado'));
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-15', '2026-12-21'))->assertCreated();

        // El periodo de asistencia de agosto esta cerrado.
        $agosto = $this->rolVacacional($this->periodoVacacional($this->nuevoVinculo()), '2026-08-03', 15);
        $this->postJson('/api/goces-vacacionales', $this->goce($agosto, '2026-08-03', '2026-08-09'))->assertStatus(422)->assertJsonValidationErrors(['GoceVacacionalFechaInicio']);
        $this->assertNotNull($periodo);
    }

    public function test_el_goce_se_pide_solo_sobre_una_programacion_vigente_de_un_periodo_abierto(): void
    {
        [, $periodo, $rol] = $this->descansoProgramado();

        foreach (['GOZADO', 'REPROGRAMADO', 'ANULADO'] as $estado) {
            DB::table('Vacaciones.RolVacacional')->where('RolVacacionalId', $rol)->update(['RolVacacionalEstado' => $estado]);
            $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalId']);
        }
        DB::table('Vacaciones.RolVacacional')->where('RolVacacionalId', $rol)->update(['RolVacacionalEstado' => 'PROGRAMADO']);
        DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $periodo)->update(['PeriodoVacacionalEstado' => 'CERRADO']);
        $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertStatus(422)->assertJsonValidationErrors(['RolVacacionalId']);
    }

    public function test_aprobar_exige_dias_disponibles_y_rechazar_pide_motivo(): void
    {
        [, $periodo, $rol] = $this->descansoProgramado();
        $goce = $this->postJson('/api/goces-vacacionales', $this->goce($rol, '2026-12-07', '2026-12-13'))->assertCreated()->json('data.id');
        $resolutor = ['UsuarioId' => $this->usuarioId('pgutierrez')];

        // El periodo ya gozo casi todo: no alcanzan los dias.
        DB::table('Vacaciones.PeriodoVacacional')->where('PeriodoVacacionalId', $periodo)->update(['PeriodoVacacionalDiasDisponibles' => 5]);
        $this->postJson("/api/goces-vacacionales/{$goce}/aprobar", $resolutor)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'días disponibles'));
        $this->assertSame('PENDIENTE', DB::table('Vacaciones.GoceVacacional')->where('GoceVacacionalId', $goce)->value('GoceVacacionalEstado'));

        $this->postJson("/api/goces-vacacionales/{$goce}/rechazar", $resolutor)->assertStatus(422)->assertJsonValidationErrors(['Motivo']);
        $this->postJson("/api/goces-vacacionales/{$goce}/rechazar", $resolutor + ['Motivo' => 'Necesidad del servicio'])->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->assertEquals(5, $this->disponibles($periodo));
        $this->patchJson("/api/goces-vacacionales/{$goce}", ['GoceVacacionalFechaFin' => '2026-12-14'])->assertStatus(422)->assertJsonValidationErrors(['GoceVacacionalEstado']);
        $this->postJson("/api/goces-vacacionales/{$goce}/rechazar", $resolutor + ['Motivo' => 'x'])->assertStatus(422);
    }

    public function test_filtros_y_datos_sembrados_de_los_goces(): void
    {
        $this->getJson('/api/goces-vacacionales?por_pagina=100')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/goces-vacacionales?estado=APROBADO')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/goces-vacacionales?estado=PENDIENTE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.dias', 15);
        $this->getJson('/api/goces-vacacionales?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/goces-vacacionales?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/goces-vacacionales?desde=2026-11-01&hasta=2026-12-31&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        // Todo goce sembrado cae dentro del descanso que cubre y los dias salen de las fechas.
        foreach (DB::table('Vacaciones.GoceVacacional as g')->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')->get() as $g) {
            $this->assertGreaterThanOrEqual($g->RolVacacionalFechaProgramada, $g->GoceVacacionalFechaInicio);
            $this->assertLessThanOrEqual($g->RolVacacionalFechaFinProgramada, $g->GoceVacacionalFechaFin);
            $this->assertEquals((strtotime($g->GoceVacacionalFechaFin) - strtotime($g->GoceVacacionalFechaInicio)) / 86400 + 1, $g->GoceVacacionalDias);
        }
    }
}
