<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel3Modulos;

/**
 * Nivel 3, lote B: papeletas, licencias, descansos medicos y constataciones domiciliarias. Los casos genericos
 * vienen de CrudModulosTestCase; aqui van el ciclo de aprobacion y las reglas del RIT (Art. 14, 23, 55, 56, 57, 107).
 */
class Nivel3SolicitudesTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel3Modulos::loteB();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    private function usuario(string $nombre = 'pgutierrez'): int
    {
        return $this->id('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    private function tipoPapeleta(string $codigo): int
    {
        return $this->id('Solicitudes.TipoPapeleta', 'TipoPapeletaId', ['TipoPapeletaCodigo' => $codigo]);
    }

    private function tipoLicencia(string $codigo): int
    {
        return $this->id('Solicitudes.TipoLicencia', 'TipoLicenciaId', ['TipoLicenciaCodigo' => $codigo]);
    }

    private function documento(string $nombre = 'certificado-medico-0001.pdf'): int
    {
        return $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => $nombre]);
    }

    private function papeleta(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'TipoPapeletaId' => $this->tipoPapeleta('PERM_OFICIAL'), 'PapeletaFecha' => '2026-09-21',
            'PapeletaHoraSalida' => '10:00', 'PapeletaHoraRetorno' => '11:00',
        ];
    }

    private function licencia(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'TipoLicenciaId' => $this->tipoLicencia('CAPACITACION'),
            'LicenciaFechaInicio' => '2026-09-21', 'LicenciaFechaFin' => '2026-09-23',
        ];
    }

    private function descanso(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'DescansoMedicoNumeroCitt' => 'ZZ-'.random_int(1000, 9999),
            'DescansoMedicoFechaInicio' => '2026-09-21', 'DescansoMedicoFechaFin' => '2026-09-23',
        ];
    }

    // ================================================================== Papeleta

    public function test_la_comision_de_servicios_vale_como_maximo_tres_horas(): void
    {
        $vinculo = $this->nuevoVinculo();
        $comision = $this->tipoPapeleta('COMISION');

        // RIT Art. 14: la papeleta de comision de servicios es valida por 3 horas.
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['TipoPapeletaId' => $comision, 'PapeletaHoraSalida' => '08:00', 'PapeletaHoraRetorno' => '11:01']))
            ->assertStatus(422)->assertJsonValidationErrors(['PapeletaHoraRetorno']);
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['TipoPapeletaId' => $comision, 'PapeletaHoraSalida' => '08:00', 'PapeletaHoraRetorno' => '11:00']))
            ->assertCreated()->assertJsonPath('data.minutos_utilizados', 180)->assertJsonPath('data.hora_salida', '08:00')->assertJsonPath('data.tipo.codigo', 'COMISION');
        // Otros permisos (p. ej. estudios) no tienen ese tope.
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['TipoPapeletaId' => $this->tipoPapeleta('ESTUDIOS'), 'PapeletaFecha' => '2026-09-22', 'PapeletaHoraSalida' => '08:00', 'PapeletaHoraRetorno' => '13:00']))
            ->assertCreated();
    }

    public function test_dia_completo_horas_y_minutos_utilizados(): void
    {
        $vinculo = $this->nuevoVinculo();

        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaHoraSalida' => null, 'PapeletaHoraRetorno' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['PapeletaHoraSalida']);
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaEsDiaCompleto' => true]))->assertStatus(422)->assertJsonValidationErrors(['PapeletaEsDiaCompleto']);

        $id = $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaEsDiaCompleto' => true, 'PapeletaHoraSalida' => null, 'PapeletaHoraRetorno' => null]))
            ->assertCreated()->assertJsonPath('data.es_dia_completo', true)->assertJsonPath('data.hora_salida', null)->json('data.id');
        // Pasar de dia completo a horas.
        $this->patchJson("/api/papeletas/{$id}", ['PapeletaEsDiaCompleto' => false, 'PapeletaHoraSalida' => '09:00', 'PapeletaHoraRetorno' => '10:30'])
            ->assertOk()->assertJsonPath('data.minutos_utilizados', 90);
        // Y de horas a dia completo: se limpian las horas.
        $this->patchJson("/api/papeletas/{$id}", ['PapeletaEsDiaCompleto' => true, 'PapeletaHoraSalida' => null, 'PapeletaHoraRetorno' => null])
            ->assertOk()->assertJsonPath('data.hora_salida', null)->assertJsonPath('data.hora_retorno', null);
        // Se pueden indicar los minutos realmente utilizados (distintos de la ventana autorizada).
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaFecha' => '2026-09-22', 'PapeletaMinutosUtilizados' => 100]))->assertCreated()->assertJsonPath('data.minutos_utilizados', 100);
    }

    public function test_el_motivo_debe_pertenecer_al_tipo(): void
    {
        $vinculo = $this->nuevoVinculo();
        $motivoDeComision = $this->id('Solicitudes.MotivoPapeleta', 'MotivoPapeletaId', ['MotivoPapeletaCodigo' => 'COM_REUNION']);

        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['MotivoPapeletaId' => $motivoDeComision]))->assertStatus(422)->assertJsonValidationErrors(['MotivoPapeletaId']);
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['TipoPapeletaId' => $this->tipoPapeleta('COMISION'), 'MotivoPapeletaId' => $motivoDeComision]))
            ->assertCreated()->assertJsonPath('data.motivo_papeleta.codigo', 'COM_REUNION');
    }

    public function test_una_papeleta_no_se_superpone_con_otra_del_mismo_dia(): void
    {
        $vinculo = $this->nuevoVinculo();
        $primera = $this->postJson('/api/papeletas', $this->papeleta($vinculo))->assertCreated()->json('data.id');   // 10:00 - 11:00

        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaHoraSalida' => '10:30', 'PapeletaHoraRetorno' => '12:00']))->assertStatus(422)->assertJsonValidationErrors(['PapeletaFecha']);
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaEsDiaCompleto' => true, 'PapeletaHoraSalida' => null, 'PapeletaHoraRetorno' => null]))->assertStatus(422);
        // Consecutiva (sale cuando la otra retorna) y de otro dia: bien.
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaHoraSalida' => '11:00', 'PapeletaHoraRetorno' => '12:00']))->assertCreated();
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaFecha' => '2026-09-22']))->assertCreated();
        // Una rechazada no cuenta.
        DB::table('Solicitudes.Papeleta')->where('PapeletaId', $primera)->update(['PapeletaEstado' => 'RECHAZADO']);
        $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['PapeletaHoraSalida' => '10:15', 'PapeletaHoraRetorno' => '10:45']))->assertCreated();
    }

    public function test_aprobar_una_papeleta_registra_quien_autoriza_y_avisa(): void
    {
        $vinculo = $this->nuevoVinculo();
        $registrante = $this->usuario('rvargas');
        $id = $this->postJson('/api/papeletas', $this->papeleta($vinculo, ['UsuarioRegistroId' => $registrante, 'DocumentoSustentoId' => $this->documento()]))
            ->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');

        $r = $this->postJson("/api/papeletas/{$id}/aprobar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'Visada por control de asistencia'])->assertOk();
        $r->assertJsonPath('data.estado', 'APROBADO')->assertJsonPath('data.usuario_autorizacion.nombre', 'pgutierrez')->assertJsonPath('data.activo', true);
        $this->assertNotNull($r->json('data.fecha_resolucion'));
        $this->assertStringContainsString('Aprobación: Visada por control de asistencia', $r->json('data.observacion'));

        $aviso = DB::table('Soporte.Notificacion')->where('UsuarioId', $registrante)->where('NotificacionTipo', 'PAPELETA_APROBADA')->first();
        $this->assertNotNull($aviso);
        $this->assertSame('/solicitudes/papeletas', $aviso->NotificacionEnlace);

        // Resuelta: no se resuelve de nuevo ni se modifica.
        $this->postJson("/api/papeletas/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->postJson("/api/papeletas/{$id}/rechazar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'x'])->assertStatus(422);
        $this->patchJson("/api/papeletas/{$id}", ['PapeletaObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['PapeletaEstado']);
    }

    public function test_el_tipo_que_exige_sustento_no_se_aprueba_sin_documento(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/papeletas', $this->papeleta($vinculo))->assertCreated()->json('data.id');   // PERM_OFICIAL exige sustento

        $this->postJson("/api/papeletas/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->assertSame('PENDIENTE', DB::table('Solicitudes.Papeleta')->where('PapeletaId', $id)->value('PapeletaEstado'));
        $this->patchJson("/api/papeletas/{$id}", ['DocumentoSustentoId' => $this->documento()])->assertOk();
        $this->postJson("/api/papeletas/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertOk();
    }

    public function test_rechazar_exige_motivo_y_se_puede_anular_cualquier_papeleta(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/papeletas', $this->papeleta($vinculo))->assertCreated()->json('data.id');

        $this->postJson("/api/papeletas/{$id}/rechazar", ['UsuarioId' => $this->usuario()])->assertStatus(422)->assertJsonValidationErrors(['Motivo']);
        $this->postJson("/api/papeletas/{$id}/rechazar", ['Motivo' => 'Sin anticipación'])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
        $this->postJson("/api/papeletas/{$id}/rechazar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'Sin anticipación'])
            ->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->assertStringContainsString('Rechazo: Sin anticipación', $this->getJson("/api/papeletas/{$id}")->json('data.observacion'));

        $this->deleteJson("/api/papeletas/{$id}")->assertOk()->assertJsonPath('mensaje', 'Papeleta anulada.');
        $this->getJson("/api/papeletas/{$id}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
        $this->deleteJson("/api/papeletas/{$id}")->assertOk();   // idempotente
        $this->postJson('/api/papeletas/999999/aprobar', ['UsuarioId' => $this->usuario()])->assertNotFound();
    }

    public function test_filtros_y_datos_sembrados_de_papeletas(): void
    {
        $this->getJson('/api/papeletas?por_pagina=100')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/papeletas?estado=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/papeletas?buscar=PS-0124')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.minutos_utilizados', 220);
        $this->getJson('/api/papeletas?tipo_papeleta_id='.$this->tipoPapeleta('COMISION').'&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/papeletas?es_dia_completo=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/papeletas?desde=2026-09-29&hasta=2026-09-29')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/papeletas?buscar=Quispe')->assertOk()->assertJsonPath('data.0.numero', 'PS-0123')->assertJsonPath('data.0.usuario_autorizacion.nombre', 'pgutierrez');
    }

    // ================================================================== Licencia

    public function test_la_licencia_no_supera_el_maximo_de_dias_del_tipo(): void
    {
        $vinculo = $this->nuevoVinculo();
        $fallecimiento = $this->tipoLicencia('FALLECIMIENTO');   // 5 dias

        $this->postJson('/api/licencias', $this->licencia($vinculo, ['TipoLicenciaId' => $fallecimiento, 'LicenciaFechaFin' => '2026-09-26']))
            ->assertStatus(422)->assertJsonValidationErrors(['LicenciaFechaFin']);
        // Los dias son corridos: de lunes a viernes son 5 (RIT, Art. 55: sabados, domingos y feriados cuentan).
        $this->postJson('/api/licencias', $this->licencia($vinculo, ['TipoLicenciaId' => $fallecimiento, 'LicenciaFechaFin' => '2026-09-25']))->assertCreated();
        // Un tipo sin maximo admite lo que corresponda.
        $this->postJson('/api/licencias', $this->licencia($vinculo, ['LicenciaFechaInicio' => '2026-10-05', 'LicenciaFechaFin' => '2026-12-31']))->assertCreated();
    }

    public function test_una_licencia_no_se_superpone_con_otra_pendiente_o_aprobada(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/licencias', $this->licencia($vinculo))->assertCreated()->json('data.id');

        $this->postJson('/api/licencias', $this->licencia($vinculo, ['LicenciaFechaInicio' => '2026-09-23', 'LicenciaFechaFin' => '2026-09-25']))->assertStatus(422)->assertJsonValidationErrors(['LicenciaFechaInicio']);
        $this->postJson('/api/licencias', $this->licencia($vinculo, ['LicenciaFechaInicio' => '2026-09-24', 'LicenciaFechaFin' => '2026-09-25']))->assertCreated();
        $this->patchJson("/api/licencias/{$id}", ['LicenciaFechaFin' => '2026-09-24'])->assertStatus(422);
        // Rechazada: libera las fechas.
        $this->postJson("/api/licencias/{$id}/rechazar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'Necesidad del servicio'])->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->postJson('/api/licencias', $this->licencia($vinculo, ['LicenciaFechaInicio' => '2026-09-22', 'LicenciaFechaFin' => '2026-09-23']))->assertCreated();
    }

    public function test_con_un_pad_en_curso_no_se_otorga_licencia_sin_goce_de_mas_de_cinco_dias(): void
    {
        $vinculo = $this->nuevoVinculo();
        $sinGoce = $this->tipoLicencia('SIN_GOCE');
        $seisDias = $this->licencia($vinculo, ['TipoLicenciaId' => $sinGoce, 'LicenciaFechaFin' => '2026-09-26']);
        $cincoDias = $this->licencia($vinculo, ['TipoLicenciaId' => $sinGoce, 'LicenciaFechaFin' => '2026-09-25']);

        $this->postJson('/api/licencias', $seisDias)->assertCreated()->json('data.id');
        DB::table('Solicitudes.Licencia')->where('VinculoLaboralId', $vinculo)->delete();

        DB::table('Disciplina.ExpedientePad')->insert([
            'VinculoLaboralId' => $vinculo, 'ExpedientePadFechaInicio' => '2026-09-01', 'ExpedientePadEstado' => 'EN_PROCESO',
            'TipoFaltaDisciplinariaId' => $this->id('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaId', ['TipoFaltaDisciplinariaCodigo' => 'ABANDONO']),
        ]);
        // RIT Art. 107: impedido de licencias por motivos particulares mayores a 5 dias.
        $this->postJson('/api/licencias', $seisDias)->assertStatus(422)->assertJsonValidationErrors(['TipoLicenciaId']);
        $this->postJson('/api/licencias', $cincoDias)->assertCreated();
        // Los otros tipos no se afectan.
        $this->postJson('/api/licencias', $this->licencia($vinculo, ['LicenciaFechaInicio' => '2026-10-05', 'LicenciaFechaFin' => '2026-10-20']))->assertCreated();
    }

    public function test_aprobar_y_rechazar_licencias(): void
    {
        $vinculo = $this->nuevoVinculo();
        $registrante = $this->usuario('rvargas');
        $id = $this->postJson('/api/licencias', $this->licencia($vinculo, ['UsuarioRegistroId' => $registrante]))->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');

        $this->postJson("/api/licencias/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertNotNull(DB::table('Soporte.Notificacion')->where('UsuarioId', $registrante)->where('NotificacionTipo', 'LICENCIA_APROBADA')->first());
        $this->postJson("/api/licencias/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->patchJson("/api/licencias/{$id}", ['LicenciaMotivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['LicenciaEstado']);
        $this->deleteJson("/api/licencias/{$id}")->assertOk();
        $this->getJson("/api/licencias/{$id}")->assertJsonPath('data.estado', 'ANULADO');
    }

    public function test_filtros_y_datos_sembrados_de_licencias(): void
    {
        $this->getJson('/api/licencias?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/licencias?estado=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/licencias?buscar=RD-0451-2026')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tipo.nombre', 'Licencia por enfermedad');
        $this->getJson('/api/licencias?tipo_licencia_id='.$this->tipoLicencia('SIN_GOCE'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tipo.con_goce', false);
        $this->getJson('/api/licencias?desde=2026-10-01&hasta=2026-12-31')->assertOk()->assertJsonCount(3, 'data');
    }

    // ================================================================== Descanso medico

    public function test_un_descanso_no_se_superpone_ni_repite_el_citt(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->postJson('/api/descansos-medicos', $this->descanso($vinculo, ['DescansoMedicoNumeroCitt' => 'ZZ-0001']))->assertCreated();

        $this->postJson('/api/descansos-medicos', $this->descanso($vinculo, ['DescansoMedicoNumeroCitt' => 'ZZ-0002', 'DescansoMedicoFechaInicio' => '2026-09-23', 'DescansoMedicoFechaFin' => '2026-09-25']))
            ->assertStatus(422)->assertJsonValidationErrors(['DescansoMedicoFechaInicio']);
        // El mismo CITT para otro trabajador: ya esta registrado.
        $this->postJson('/api/descansos-medicos', $this->descanso($this->nuevoVinculo(), ['DescansoMedicoNumeroCitt' => 'ZZ-0001']))
            ->assertStatus(422)->assertJsonValidationErrors(['DescansoMedicoNumeroCitt']);
        // Consecutivo (empieza cuando termina el anterior): bien.
        $this->postJson('/api/descansos-medicos', $this->descanso($vinculo, ['DescansoMedicoNumeroCitt' => 'ZZ-0003', 'DescansoMedicoFechaInicio' => '2026-09-24', 'DescansoMedicoFechaFin' => '2026-09-25']))
            ->assertCreated();
    }

    public function test_el_descanso_sin_citt_ni_documento_no_se_aprueba(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/descansos-medicos', $this->descanso($vinculo, ['DescansoMedicoNumeroCitt' => null]))->assertCreated()->json('data.id');

        $this->postJson("/api/descansos-medicos/{$id}/aprobar", ['UsuarioId' => $this->usuario()])->assertStatus(422);
        $this->patchJson("/api/descansos-medicos/{$id}", ['DescansoMedicoNumeroCitt' => 'ZZ-7777'])->assertOk();
        $this->postJson("/api/descansos-medicos/{$id}/aprobar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'CITT verificado'])->assertOk()->assertJsonPath('data.estado', 'APROBADO');
        $this->assertStringContainsString('Aprobación: CITT verificado', $this->getJson("/api/descansos-medicos/{$id}")->json('data.observacion'));

        $otro = $this->postJson('/api/descansos-medicos', $this->descanso($this->nuevoVinculo()))->assertCreated()->json('data.id');
        $this->postJson("/api/descansos-medicos/{$otro}/rechazar", ['UsuarioId' => $this->usuario(), 'Motivo' => 'Certificado ilegible'])->assertOk()->assertJsonPath('data.estado', 'RECHAZADO');
        $this->deleteJson("/api/descansos-medicos/{$otro}")->assertOk()->assertJsonPath('mensaje', 'Descanso médico anulado.');
    }

    public function test_filtros_y_datos_sembrados_de_descansos(): void
    {
        $this->getJson('/api/descansos-medicos?por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/descansos-medicos?buscar=CITT-4471')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.diagnostico', 'Lumbalgia aguda')->assertJsonPath('data.0.documento.nombre', 'certificado-medico-0001.pdf');
        $this->getJson('/api/descansos-medicos?estado=PENDIENTE')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/descansos-medicos?buscar=Gastroenteritis')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/descansos-medicos?desde=2026-09-01&hasta=2026-09-30')->assertOk()->assertJsonCount(3, 'data');
    }

    // ================================================================== Constatacion domiciliaria

    public function test_la_visita_se_hace_dentro_del_descanso_del_mismo_trabajador(): void
    {
        $vinculo = $this->nuevoVinculo();
        $descansoId = $this->postJson('/api/descansos-medicos', $this->descanso($vinculo))->assertCreated()->json('data.id');   // 21 al 23
        $base = ['VinculoLaboralId' => $vinculo, 'DescansoMedicoId' => $descansoId, 'ConstatacionDomiciliariaFecha' => '2026-09-22', 'ConstatacionDomiciliariaDireccion' => 'Av. Prueba 123'];

        $this->postJson('/api/constataciones-domiciliarias', ['ConstatacionDomiciliariaFecha' => '2026-09-25'] + $base)->assertStatus(422)->assertJsonValidationErrors(['ConstatacionDomiciliariaFecha']);
        $this->postJson('/api/constataciones-domiciliarias', ['VinculoLaboralId' => $this->nuevoVinculo()] + $base)->assertStatus(422)->assertJsonValidationErrors(['DescansoMedicoId']);
        $this->postJson('/api/constataciones-domiciliarias', $base)->assertCreated()->assertJsonPath('data.descanso.id', $descansoId)->assertJsonPath('data.estado', 'PENDIENTE');

        // Un descanso rechazado o anulado ya no se constata.
        $this->deleteJson("/api/descansos-medicos/{$descansoId}")->assertOk();
        $this->postJson('/api/constataciones-domiciliarias', ['ConstatacionDomiciliariaFecha' => '2026-09-23'] + $base)->assertStatus(422)->assertJsonValidationErrors(['DescansoMedicoId']);
    }

    public function test_resolver_la_constatacion_exige_el_resultado_y_la_deja_como_constancia(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/constataciones-domiciliarias', ['VinculoLaboralId' => $vinculo, 'ConstatacionDomiciliariaFecha' => '2026-09-22'])->assertCreated()->json('data.id');

        $this->patchJson("/api/constataciones-domiciliarias/{$id}", ['ConstatacionDomiciliariaEstado' => 'CONFORME'])->assertStatus(422)->assertJsonValidationErrors(['ConstatacionDomiciliariaResultado']);
        $this->patchJson("/api/constataciones-domiciliarias/{$id}", ['ConstatacionDomiciliariaEstado' => 'NO_CONFORME', 'ConstatacionDomiciliariaResultado' => 'No se encontró al servidor'])
            ->assertOk()->assertJsonPath('data.estado', 'NO_CONFORME')->assertJsonPath('data.activo', true);
        // Resuelta: no se modifica, pero si se puede anular.
        $this->patchJson("/api/constataciones-domiciliarias/{$id}", ['ConstatacionDomiciliariaDireccion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['ConstatacionDomiciliariaEstado']);
        $this->deleteJson("/api/constataciones-domiciliarias/{$id}")->assertOk()->assertJsonPath('mensaje', 'Constatación anulada.');
        $this->getJson("/api/constataciones-domiciliarias/{$id}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
    }

    public function test_filtros_y_datos_sembrados_de_constataciones(): void
    {
        $this->getJson('/api/constataciones-domiciliarias?por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/constataciones-domiciliarias?estado=NO_CONFORME')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.descanso.numero_citt', 'CITT-4390');
        $this->getJson('/api/constataciones-domiciliarias?estado=PENDIENTE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.resultado', null);
        $this->getJson('/api/constataciones-domiciliarias?buscar=Los Pinos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.usuario_registro.nombre', 'gdangelo');
        $this->getJson('/api/constataciones-domiciliarias?descanso_medico_id='.$this->id('Solicitudes.DescansoMedico', 'DescansoMedicoId', ['DescansoMedicoNumeroCitt' => 'CITT-4471']))->assertOk()->assertJsonCount(1, 'data');
    }
}
