<?php

namespace Tests\Feature;

use App\Http\Resources\ExpedientePadResource;
use App\Services\AutomatizacionService;
use App\Services\LiquidacionDescuentoService;
use App\Services\MarcacionManualService;
use App\Support\HoraLocal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Reglas del RIT agregadas tras la evaluacion del 2026-11-01. Aqui los plazos estan activos, como en operacion normal.
 */
class Nivel13ReglasRitTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rit.control_de_plazos' => true]);
    }

    private function tipoLicencia(string $codigo): int
    {
        return $this->idPorCodigo('Solicitudes.TipoLicencia', 'TipoLicenciaId', 'TipoLicenciaCodigo', $codigo);
    }

    private function tipoPapeleta(string $codigo): int
    {
        return $this->idPorCodigo('Solicitudes.TipoPapeleta', 'TipoPapeletaId', 'TipoPapeletaCodigo', $codigo);
    }

    private function licencia(int $vinculo, string $tipo, string $inicio, string $fin, array $extra = []): TestResponse
    {
        return $this->postJson('/api/licencias', $extra + ['VinculoLaboralId' => $vinculo, 'TipoLicenciaId' => $this->tipoLicencia($tipo), 'LicenciaFechaInicio' => $inicio, 'LicenciaFechaFin' => $fin, 'LicenciaMotivo' => 'Prueba']);
    }

    private function vinculoDe(string $sexo, string $regimen = 'DL276'): int
    {
        $trabajador = $this->nuevoTrabajador();
        DB::table('Personal.Trabajador')->where('TrabajadorId', $trabajador)->update(['TrabajadorSexo' => $sexo]);

        return $this->nuevoVinculo(['RegimenLaboralId' => $this->idPorCodigo('Personal.RegimenLaboral', 'RegimenLaboralId', 'RegimenLaboralCodigo', $regimen)], $trabajador);
    }

    // ------------------------------------------------------------------ alcance por registro

    public function test_nadie_abre_ni_aprueba_registros_fuera_de_su_alcance(): void
    {
        $ajeno = $this->nuevoVinculo();
        $id = $this->licencia($ajeno, 'CAPACITACION', '2026-11-10', '2026-11-11')->assertCreated()->json('data.id');

        $this->actuarComo('crojas');   // responsable de otro establecimiento
        $this->getJson("/api/licencias/{$id}")->assertForbidden();
        $this->postJson("/api/licencias/{$id}/aprobar")->assertForbidden();
        $this->getJson("/api/vinculos-laborales/{$ajeno}")->assertForbidden();
        $this->assertSame('PENDIENTE', DB::table('Solicitudes.Licencia')->where('LicenciaId', $id)->value('LicenciaEstado'));
        $this->postJson('/api/licencias', ['VinculoLaboralId' => $ajeno, 'TipoLicenciaId' => $this->tipoLicencia('CAPACITACION'), 'LicenciaFechaInicio' => '2026-11-20', 'LicenciaFechaFin' => '2026-11-20'])->assertForbidden();
    }

    public function test_el_trabajador_no_ve_el_diagnostico_de_otro_y_porteria_no_abre_sustentos(): void
    {
        $id = (int) DB::table('Solicitudes.DescansoMedico')->insertGetId(['VinculoLaboralId' => $this->nuevoVinculo(), 'DescansoMedicoDiagnostico' => 'Reservado', 'DescansoMedicoFechaInicio' => '2026-10-01', 'DescansoMedicoFechaFin' => '2026-10-02']);

        $trabajador = DB::table('Seguridad.UsuarioRol as ur')->join('Seguridad.Rol as r', 'r.RolId', '=', 'ur.RolId')->join('Seguridad.Usuario as u', 'u.UsuarioId', '=', 'ur.UsuarioId')
            ->where('r.RolCodigo', 'TRABAJADOR')->value('u.UsuarioNombre');
        $this->actuarComo($trabajador);
        $this->getJson("/api/descansos-medicos/{$id}")->assertForbidden();

        $this->actuarComo('gdangelo');
        $this->getJson("/api/descansos-medicos/{$id}")->assertOk()->assertJsonPath('data.diagnostico', 'Reservado');
    }

    public function test_un_id_que_no_es_numero_responde_404(): void
    {
        $this->getJson('/api/licencias/abc')->assertNotFound();
    }

    // ------------------------------------------------------------------ licencias

    public function test_topes_de_licencia_por_caso_especial_sexo_y_regimen(): void
    {
        $mujer = $this->vinculoDe('F');
        $this->licencia($mujer, 'MATERNIDAD', '2026-12-01', '2027-04-07')->assertStatus(422);
        $this->licencia($mujer, 'MATERNIDAD', '2026-12-01', '2027-04-07', ['LicenciaCasoEspecial' => 'PARTO_MULTIPLE'])->assertCreated();
        $this->licencia($mujer, 'PATERNIDAD', '2027-06-01', '2027-06-05')->assertStatus(422)->assertJsonValidationErrors('TipoLicenciaId');

        $hombre = $this->vinculoDe('M');
        $this->licencia($hombre, 'PATERNIDAD', '2026-12-01', '2026-12-20')->assertStatus(422);
        $this->licencia($hombre, 'PATERNIDAD', '2026-12-01', '2026-12-20', ['LicenciaCasoEspecial' => 'PREMATURO'])->assertCreated();

        $cas = $this->vinculoDe('F', 'DL1057');
        $this->licencia($cas, 'FALLECIMIENTO', '2026-12-01', '2026-12-05')->assertStatus(422);
        $this->licencia($cas, 'FALLECIMIENTO', '2026-12-01', '2026-12-03')->assertCreated();
        $this->licencia($cas, 'SIN_GOCE', '2027-01-01', '2027-04-30')->assertStatus(422);
    }

    public function test_la_licencia_tardia_se_regulariza_solo_siete_dias_habiles(): void
    {
        $v = $this->nuevoVinculo();
        $this->licencia($v, 'CAPACITACION', '2026-09-01', '2026-09-02')->assertStatus(422)->assertJsonValidationErrors('LicenciaFechaInicio');
        $this->licencia($v, 'CAPACITACION', '2026-10-15', '2026-10-16')->assertCreated()->assertJsonPath('data.es_extemporanea', true);
    }

    public function test_el_jefe_da_visto_bueno_y_rrhh_aprueba(): void
    {
        $v = $this->nuevoVinculo();
        $id = $this->licencia($v, 'CAPACITACION', '2026-11-10', '2026-11-11')->assertCreated()->json('data.id');
        $this->darAlcance('crojas', $this->eessId());

        $this->actuarComo('crojas');
        $this->postJson("/api/licencias/{$id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'PENDIENTE')->assertJsonPath('data.visto_bueno', true);

        $this->actuarComo('gdangelo');
        $this->postJson("/api/licencias/{$id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'APROBADO');
    }

    public function test_treinta_dias_exigen_entrega_de_cargo(): void
    {
        $v = $this->vinculoDe('M');
        $id = $this->licencia($v, 'SIN_GOCE', '2026-12-01', '2026-12-30')->assertCreated()->json('data.id');
        $this->postJson("/api/licencias/{$id}/aprobar")->assertStatus(422);

        $this->postJson('/api/entregas-cargo', ['VinculoLaboralId' => $v, 'EntregaCargoMotivo' => 'LICENCIA', 'EntregaCargoFecha' => '2026-11-28'])->assertCreated();
        $this->postJson("/api/licencias/{$id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'APROBADO');
    }

    // ------------------------------------------------------------------ permisos y descanso medico

    public function test_permisos_anticipacion_lactancia_y_estudios(): void
    {
        $mujer = $this->vinculoDe('F');
        $base = ['VinculoLaboralId' => $mujer, 'PapeletaHoraSalida' => '08:00', 'PapeletaMotivo' => 'Prueba'];

        $this->postJson('/api/papeletas', $base + ['TipoPapeletaId' => $this->tipoPapeleta('PERM_PARTIC'), 'PapeletaFecha' => '2026-10-20', 'PapeletaHoraRetorno' => '09:00'])->assertStatus(422)->assertJsonValidationErrors('PapeletaFecha');
        $this->postJson('/api/papeletas', $base + ['TipoPapeletaId' => $this->tipoPapeleta('PERM_PARTIC'), 'PapeletaFecha' => '2026-10-20', 'PapeletaHoraRetorno' => '09:00', 'PapeletaEsUrgente' => true])->assertCreated();

        $lactancia = $base + ['TipoPapeletaId' => $this->tipoPapeleta('LACTANCIA'), 'PapeletaFecha' => '2026-10-26', 'PapeletaFechaNacimientoHijo' => '2026-05-01'];
        $this->postJson('/api/papeletas', $lactancia + ['PapeletaHoraRetorno' => '10:00'])->assertStatus(422)->assertJsonValidationErrors('PapeletaHoraRetorno');
        $this->postJson('/api/papeletas', $lactancia + ['PapeletaHoraRetorno' => '10:00', 'PapeletaPartoMultiple' => true])->assertCreated();
        $hombre = ['VinculoLaboralId' => $this->vinculoDe('M')] + $lactancia;
        $this->postJson('/api/papeletas', $hombre + ['PapeletaHoraRetorno' => '09:00'])->assertStatus(422)->assertJsonValidationErrors('VinculoLaboralId');

        $estudios = ['VinculoLaboralId' => $mujer, 'TipoPapeletaId' => $this->tipoPapeleta('ESTUDIOS'), 'PapeletaMotivo' => 'Clases', 'PapeletaHoraSalida' => '08:00', 'PapeletaHoraRetorno' => '12:00', 'DocumentoSustentoId' => null];
        $this->postJson('/api/papeletas', $estudios + ['PapeletaFecha' => '2026-11-02'])->assertCreated();
        $this->postJson('/api/papeletas', $estudios + ['PapeletaFecha' => '2026-11-03'])->assertStatus(422)->assertJsonValidationErrors('PapeletaHoraRetorno');
    }

    public function test_descanso_medico_exige_sustento_plazo_y_citt_desde_el_dia_21(): void
    {
        $v = $this->nuevoVinculo();
        $this->postJson('/api/descansos-medicos', ['VinculoLaboralId' => $v, 'DescansoMedicoFechaInicio' => '2026-10-19', 'DescansoMedicoFechaFin' => '2026-10-20'])->assertStatus(422)->assertJsonValidationErrors('DocumentoSustentoId');
        $this->postJson('/api/descansos-medicos', ['VinculoLaboralId' => $v, 'DescansoMedicoNumeroCitt' => 'A-1', 'DescansoMedicoFechaInicio' => '2026-09-01', 'DescansoMedicoFechaFin' => '2026-09-02'])->assertStatus(422)->assertJsonValidationErrors('DescansoMedicoFechaInicio');
        $doc = (int) DB::table('Soporte.DocumentoSustento')->insertGetId(['DocumentoSustentoNombre' => 'certificado.pdf']);
        $this->postJson('/api/descansos-medicos', ['VinculoLaboralId' => $v, 'DocumentoSustentoId' => $doc, 'DescansoMedicoFechaInicio' => '2026-10-19', 'DescansoMedicoFechaFin' => '2026-11-12'])->assertStatus(422)->assertJsonValidationErrors('DescansoMedicoNumeroCitt');
    }

    // ------------------------------------------------------------------ programacion

    public function test_limites_de_jornada_al_programar(): void
    {
        $v = $this->nuevoVinculoDeGuardia();
        $m = $this->idPorCodigo('Configuracion.Turno', 'TurnoId', 'TurnoCodigo', 'M');
        $celda = fn (string $dia) => $this->putJson('/api/programacion-mensual/celda', ['eess_id' => $this->eessId(), 'VinculoLaboralId' => $v, 'Fecha' => "2026-12-{$dia}", 'TurnoIds' => [$m]]);
        for ($d = 1; $d <= 25; $d++) {
            $celda(str_pad((string) $d, 2, '0', STR_PAD_LEFT))->assertOk();
        }
        $celda('26')->assertStatus(422);
    }

    public function test_publicar_tarde_pide_motivo_y_solo_hay_dos_reprogramaciones(): void
    {
        $id = (int) DB::table('Programacion.ProgramacionPeriodo')->insertGetId([
            'EessId' => $this->eessId('EESS-EP-01'), 'TipoPeriodoProgramacionId' => $this->idPorCodigo('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionId', 'TipoPeriodoProgramacionCodigo', 'MENSUAL'),
            'UsuarioRegistroId' => $this->usuarioId('pgutierrez'), 'ProgramacionPeriodoAnio' => 2026, 'ProgramacionPeriodoMes' => 10, 'ProgramacionPeriodoFechaInicio' => '2026-10-01', 'ProgramacionPeriodoFechaFin' => '2026-10-31', 'ProgramacionPeriodoEstado' => 'BORRADOR',
        ]);
        $this->postJson("/api/programaciones-periodo/{$id}/publicar")->assertStatus(422);
        $this->postJson("/api/programaciones-periodo/{$id}/publicar", ['MotivoTardio' => 'Falta de personal'])->assertOk();

        $this->postJson("/api/programaciones-periodo/{$id}/reprogramar", ['Motivo' => 'Corrige turnos', 'Excepcional' => false])->assertOk();
        $this->postJson("/api/programaciones-periodo/{$id}/publicar")->assertOk();
        $this->postJson("/api/programaciones-periodo/{$id}/reprogramar", ['Motivo' => 'Corrige turnos'])->assertOk();
        $this->postJson("/api/programaciones-periodo/{$id}/publicar")->assertOk();
        $this->postJson("/api/programaciones-periodo/{$id}/reprogramar", ['Motivo' => 'Corrige turnos'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ marcacion y descuentos

    public function test_la_marca_manual_exige_autorizacion_si_el_establecimiento_tiene_reloj(): void
    {
        $eess = $this->eessId();
        $v = $this->nuevoVinculo();
        $this->assertFalse(MarcacionManualService::autorizada($v, $eess, '2026-10-20'));
        $this->postJson('/api/asistencia-diaria/marcar', ['VinculoLaboralId' => $v, 'MarcacionTipo' => 'ENTRADA'])->assertStatus(422);

        $this->postJson('/api/contingencias-marcacion', ['EessId' => $eess, 'ContingenciaMarcacionFechaInicio' => '2026-10-20', 'ContingenciaMarcacionFechaFin' => '2026-10-20', 'ContingenciaMarcacionMotivo' => 'Reloj averiado'])->assertCreated();
        $this->assertTrue(MarcacionManualService::autorizada($v, $eess, '2026-10-20'));
    }

    public function test_tardanza_solo_se_descuenta_al_superar_una_hora_al_mes(): void
    {
        $this->assertSame(0.0, LiquidacionDescuentoService::horasDeTardanza(60));
        $this->assertSame(1.0, LiquidacionDescuentoService::horasDeTardanza(90));
        $this->assertSame(2.0, LiquidacionDescuentoService::horasDeTardanza(91));

        DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', 'TARDANZA_MODO_DESCUENTO')->update(['ParametroSistemaValor' => 'PROPORCIONAL']);
        $this->assertSame(1.5, LiquidacionDescuentoService::horasDeTardanza(90));
    }

    // ------------------------------------------------------------------ registros del RIT y documentos

    public function test_registros_del_rit_con_sus_reglas(): void
    {
        $v = $this->nuevoVinculo();
        $trabajador = (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $v)->value('TrabajadorId');

        $this->postJson('/api/fotochecks', ['TrabajadorId' => $trabajador, 'FotocheckNumero' => 'F-001', 'FotocheckFechaEntrega' => '2026-10-01'])->assertCreated()->assertJsonPath('data.numero', 'F-001');
        $this->postJson('/api/fotochecks', ['TrabajadorId' => $trabajador, 'FotocheckNumero' => 'F-002', 'FotocheckFechaEntrega' => '2026-10-02'])->assertStatus(422);
        $this->postJson('/api/legajo-documentos', ['TrabajadorId' => $trabajador, 'LegajoDocumentoTipo' => 'CONTRATO', 'LegajoDocumentoNombre' => 'Contrato 2026', 'LegajoDocumentoFecha' => '2026-01-01'])->assertCreated();
        $this->postJson('/api/desplazamientos-temporales', ['VinculoLaboralId' => $v, 'EessDestinoId' => $this->eessId(), 'DesplazamientoTemporalTipo' => 'ROTACION', 'DesplazamientoTemporalFechaInicio' => '2026-11-01', 'DesplazamientoTemporalFechaFin' => '2026-11-30'])->assertStatus(422);
        $this->postJson('/api/avisos-ausencia', ['VinculoLaboralId' => $v, 'AvisoAusenciaFecha' => '2026-10-20', 'AvisoAusenciaMedio' => 'TELEFONO', 'AvisoAusenciaMotivo' => 'Emergencia familiar'])->assertCreated();
        foreach ([1, 2] as $n) {
            $this->postJson('/api/reconocimientos', ['VinculoLaboralId' => $v, 'ReconocimientoServidorAnio' => 2026, 'ReconocimientoServidorTipo' => 'FELICITACION', 'ReconocimientoServidorMotivo' => "Puntualidad {$n}", 'ReconocimientoServidorFecha' => '2026-10-01'])->assertCreated();
        }
        $this->postJson('/api/reconocimientos', ['VinculoLaboralId' => $v, 'ReconocimientoServidorAnio' => 2026, 'ReconocimientoServidorTipo' => 'DIPLOMA', 'ReconocimientoServidorMotivo' => 'Tercero', 'ReconocimientoServidorFecha' => '2026-10-02'])->assertStatus(422);
    }

    public function test_anexos_oficiales_y_documentos_de_trabajo(): void
    {
        $periodo = (int) DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => 9])->value('PeriodoAsistenciaId');
        foreach (['012', '013', '014', '015', '016'] as $anexo) {
            $r = $this->get("/api/reportes/anexos/{$anexo}?periodo_asistencia_id={$periodo}&eess_id={$this->eessId()}")->assertOk();
            $this->assertStringStartsWith('%PDF', $r->getContent());
        }
        $this->get("/api/reportes/anexos/017?eess_id={$this->eessId()}&fecha=2026-10-20")->assertOk();
        $this->get("/api/reportes/anexos/099?eess_id={$this->eessId()}&periodo_asistencia_id={$periodo}")->assertNotFound();
        $this->get("/api/reportes/planilla-descuentos?periodo_asistencia_id={$periodo}")->assertOk();

        $v = $this->nuevoVinculo();
        $this->get("/api/vinculos-laborales/{$v}/documento?tipo=constancia")->assertOk();
        $this->getJson("/api/vinculos-laborales/{$v}/documento?tipo=certificado")->assertStatus(422);
    }

    public function test_el_acto_civico_se_autoriza_una_vez_al_mes_y_el_pad_expone_sus_autoridades(): void
    {
        $v = $this->nuevoVinculo();
        $tipo = $this->idPorCodigo('Compensaciones.TipoCompensacion', 'TipoCompensacionId', 'TipoCompensacionCodigo', 'ACTO_CIVICO');
        $datos = ['VinculoLaboralId' => $v, 'TipoCompensacionId' => $tipo];
        $this->postJson('/api/compensaciones-horarias', $datos + ['CompensacionHorariaFechaTrabajo' => '2026-10-22'])->assertCreated();
        $this->postJson('/api/compensaciones-horarias', $datos + ['CompensacionHorariaFechaTrabajo' => '2026-10-23'])->assertStatus(422)->assertJsonValidationErrors('TipoCompensacionId');
        $this->postJson('/api/compensaciones-horarias', ['VinculoLaboralId' => $v, 'TipoCompensacionId' => null, 'CompensacionHorariaFechaTrabajo' => '2026-10-24'])->assertCreated();

        $this->assertSame('Director Ejecutivo', ExpedientePadResource::autoridades('Destitución')['sancionador']);
    }

    public function test_avisos_automaticos_y_dias_no_laborables(): void
    {
        $v = $this->nuevoVinculo();
        $estado = $this->idPorCodigo('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', 'EstadoAsistenciaCodigo', 'TARDANZA');
        foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $fecha) {
            DB::table('Asistencia.AsistenciaDiaria')->insert(['VinculoLaboralId' => $v, 'EstadoAsistenciaId' => $estado, 'AsistenciaDiariaFecha' => $fecha, 'AsistenciaDiariaMinutosTardanza' => 30]);
        }
        $servicio = app(AutomatizacionService::class);
        $this->assertGreaterThan(0, $servicio->detectarTardanzasAcumuladas());
        $this->assertSame(0, $servicio->detectarTardanzasAcumuladas(), 'avisa una sola vez');
        $this->assertSame(HoraLocal::hoy()->toDateString(), '2026-10-20');
    }
}
