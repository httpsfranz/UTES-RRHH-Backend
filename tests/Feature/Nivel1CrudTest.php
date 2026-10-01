<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel1Modulos;

/**
 * Modulos de Nivel 1 (9 tablas que dependen de un catalogo de Nivel 0): EstablecimientoSalud, Trabajador,
 * Cargo, Turno, Horario, ParametroJornada, TramoTolerancia, MotivoPapeleta y RolPermiso. Los casos
 * genericos vienen de CrudModulosTestCase; aqui van las reglas propias de cada modulo (RIT incluido).
 */
class Nivel1CrudTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel1Modulos::all();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    // ------------------------------------------------------------------ Establecimiento de salud

    public function test_el_nombre_del_establecimiento_solo_se_repite_en_otra_microred(): void
    {
        $spec = $this->spec('establecimientos');
        $this->crear($spec);

        $this->postJson($spec['endpoint'], $this->payload($spec, ['EessCodigo' => 'ZZ-EESS-B', 'EessCodigoRenipres' => '99999902']))
            ->assertStatus(422)->assertJsonValidationErrors(['EessNombre']);

        $otraMicrored = $this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-EP']);
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'EessCodigo' => 'ZZ-EESS-B', 'EessCodigoRenipres' => '99999902', 'MicroredId' => $otraMicrored,
        ]))->assertCreated();
    }

    public function test_no_se_crea_un_establecimiento_en_una_microred_inactiva_pero_si_se_edita_uno_existente(): void
    {
        $spec = $this->spec('establecimientos');
        $inactiva = $this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-PO']);

        $this->postJson($spec['endpoint'], $this->payload($spec, ['MicroredId' => $inactiva]))
            ->assertStatus(422)->assertJsonValidationErrors(['MicroredId']);

        // EESS-PO-01 pertenece a la microred inactiva: reenviar el formulario completo no debe fallar por eso.
        $existente = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-PO-01']);
        $this->patchJson("{$spec['endpoint']}/{$existente}", ['MicroredId' => $inactiva, 'EessDescripcion' => 'Editado'])->assertOk();
    }

    public function test_filtros_del_listado_de_establecimientos(): void
    {
        $microred = $this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-LE']);

        $this->getJson("/api/establecimientos?microred_id={$microred}&por_pagina=100")
            ->assertOk()->assertJsonPath('data.0.microred.id', $microred);
        $this->getJson('/api/establecimientos?buscar=Esperanza')->assertOk()->assertJsonPath('data.0.nombre', 'C.S. La Esperanza');
        $this->getJson('/api/establecimientos?estado=0')->assertOk()->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/establecimientos?categoria=I-4&por_pagina=100')->assertOk()->assertJsonPath('data.0.categoria', 'I-4');
    }

    // ------------------------------------------------------------------ Trabajador

    public function test_el_formato_del_documento_depende_del_tipo(): void
    {
        $spec = $this->spec('trabajadores');
        $ce = $this->id('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'CE']);
        $pas = $this->id('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'PAS']);

        // Carne de extranjeria: solo digitos, de 9 a 12.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $ce, 'TrabajadorNumeroDocumento' => '12345678']))
            ->assertStatus(422)->assertJsonValidationErrors(['TrabajadorNumeroDocumento']);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $ce, 'TrabajadorNumeroDocumento' => '12345678A']))
            ->assertStatus(422);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $ce, 'TrabajadorNumeroDocumento' => '123456789']))
            ->assertCreated();

        // Pasaporte: alfanumerico.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $pas, 'TrabajadorNumeroDocumento' => 'ZZ987654']))
            ->assertCreated();
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $pas, 'TrabajadorNumeroDocumento' => 'AB-1']))
            ->assertStatus(422);
    }

    public function test_el_mismo_numero_se_puede_usar_en_otro_tipo_de_documento_pero_no_en_el_mismo(): void
    {
        $spec = $this->spec('trabajadores');
        $pas = $this->id('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'PAS']);

        $this->crear($spec); // DNI 79999901
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TipoDocumentoIdentidadId' => $pas, 'TrabajadorNumeroDocumento' => '79999901']))
            ->assertCreated();
        $this->postJson($spec['endpoint'], $this->payload($spec))->assertStatus(422);
    }

    public function test_el_trabajador_expone_nombre_completo_calculado_por_la_base_y_sus_relaciones(): void
    {
        $spec = $this->spec('trabajadores');
        $profesion = $this->id('Personal.Profesion', 'ProfesionId', ['ProfesionCodigo' => 'ENFERMERIA']);

        $r = $this->postJson($spec['endpoint'], $this->payload($spec, ['ProfesionId' => $profesion]))->assertCreated();

        $r->assertJsonPath('data.nombre_completo', 'Prueba Zúñiga, Ana María')
            ->assertJsonPath('data.profesion.id', $profesion)
            ->assertJsonPath('data.tipo_documento.codigo', 'DNI')
            ->assertJsonPath('data.fecha_nacimiento', '1990-05-20');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r->json('data.fecha_registro'));

        // El nombre completo no se puede escribir: lo arma la base.
        $id = $r->json('data.id');
        $this->patchJson("{$spec['endpoint']}/{$id}", ['TrabajadorNombreCompleto' => 'Otro, Nombre'])->assertOk();
        $this->getJson("{$spec['endpoint']}/{$id}")->assertJsonPath('data.nombre_completo', 'Prueba Zúñiga, Ana María');
    }

    public function test_busqueda_y_filtros_de_trabajadores(): void
    {
        $this->getJson('/api/trabajadores?buscar=70000001')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombres', 'María Elena');
        $this->getJson('/api/trabajadores?buscar=Quispe')->assertOk()->assertJsonPath('data.0.apellido_paterno', 'Quispe');
        $this->getJson('/api/trabajadores?estado=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $ce = $this->id('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'CE']);
        $this->getJson("/api/trabajadores?tipo_documento_id={$ce}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/trabajadores?sexo=M&por_pagina=100')->assertOk()->assertJsonPath('data.0.sexo', 'M');
    }

    // ------------------------------------------------------------------ Cargo

    public function test_el_codigo_del_cargo_es_opcional_y_unico_solo_cuando_existe(): void
    {
        $spec = $this->spec('cargos');
        $sinCodigo = fn (string $nombre) => $this->payload($spec, ['CargoCodigo' => null, 'CargoNombre' => $nombre]);

        // Varios cargos sin codigo conviven (indice unico filtrado), con codigo no.
        $this->postJson($spec['endpoint'], $sinCodigo('Cargo sin código A'))->assertCreated();
        $this->postJson($spec['endpoint'], $sinCodigo('Cargo sin código B'))->assertCreated();
        $this->crear($spec);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['CargoNombre' => 'Otro nombre ZZ']))
            ->assertStatus(422)->assertJsonValidationErrors(['CargoCodigo']);
    }

    // ------------------------------------------------------------------ Turno

    public function test_el_turno_expone_hora_corta_duracion_y_cruce_de_medianoche_calculados_por_la_base(): void
    {
        $spec = $this->spec('turnos');
        $guardia = $this->id('Configuracion.TipoJornada', 'TipoJornadaId', ['TipoJornadaCodigo' => 'GUARDIA']);

        $noche = $this->postJson($spec['endpoint'], $this->payload($spec, [
            'TipoJornadaId' => $guardia, 'TurnoEsGuardia' => true, 'TurnoHoraEntrada' => '19:30', 'TurnoHoraSalida' => '07:30',
        ]))->assertCreated();
        $noche->assertJsonPath('data.hora_entrada', '19:30')->assertJsonPath('data.hora_salida', '07:30')
            ->assertJsonPath('data.cruza_medianoche', true)->assertJsonPath('data.duracion_minutos', 720);

        $admin = $this->postJson($spec['endpoint'], $this->payload($spec, [
            'TurnoCodigo' => 'ZZ-ADM', 'TurnoNombre' => 'Admin ZZ', 'TurnoHoraEntrada' => '07:30', 'TurnoHoraSalida' => '15:30', 'TurnoRefrigerioMinutos' => 45,
        ]))->assertCreated();
        $admin->assertJsonPath('data.cruza_medianoche', false)->assertJsonPath('data.duracion_minutos', 480)
            ->assertJsonPath('data.refrigerio_minutos', 45);
    }

    public function test_los_turnos_sembrados_siguen_el_rit(): void
    {
        $turnos = collect($this->getJson('/api/turnos?por_pagina=100')->assertOk()->json('data'))->keyBy('codigo');

        $this->assertSame(['07:30', '15:30', 45, false], [$turnos['ADM-D']['hora_entrada'], $turnos['ADM-D']['hora_salida'], $turnos['ADM-D']['refrigerio_minutos'], $turnos['ADM-D']['es_guardia']]);
        $this->assertSame(['07:30', '13:30'], [$turnos['M']['hora_entrada'], $turnos['M']['hora_salida']]);
        $this->assertSame(['13:30', '19:30'], [$turnos['T']['hora_entrada'], $turnos['T']['hora_salida']]);
        $this->assertSame(['19:30', '07:30', 720, true], [$turnos['N']['hora_entrada'], $turnos['N']['hora_salida'], $turnos['N']['duracion_minutos'], $turnos['N']['es_guardia']]);
        $this->assertSame(720, $turnos['G12-D']['duracion_minutos']);
        $this->assertFalse($turnos->has('G24'), 'El RIT (Art. 20) prohibe las guardias de 24 horas continuas.');
        $this->assertSame(5, $turnos['M']['tolerancia_entrada_minutos']);
        $this->assertSame('RIT_GENERAL', DB::table('Configuracion.TablaTolerancia')->where('TablaToleranciaId', $turnos['M']['tabla_tolerancia_id'])->value('TablaToleranciaCodigo'));
    }

    public function test_filtro_de_turnos_por_guardia_y_jornada(): void
    {
        $this->getJson('/api/turnos?es_guardia=1&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/turnos?buscar=Mañana')->assertOk()->assertJsonPath('data.0.codigo', 'M');
    }

    // ------------------------------------------------------------------ Horario

    public function test_el_horario_puede_ser_de_toda_la_red_o_de_un_establecimiento(): void
    {
        $spec = $this->spec('horarios');
        $eess = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']);

        $this->postJson($spec['endpoint'], $this->payload($spec))->assertCreated()->assertJsonPath('data.eess_id', null)->assertJsonPath('data.eess', null);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['HorarioCodigo' => 'ZZ-HOR2', 'HorarioNombre' => 'Horario ZZ 2', 'EessId' => $eess]))
            ->assertCreated()->assertJsonPath('data.eess.id', $eess);

        $inactivo = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-PO-01']);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['HorarioCodigo' => 'ZZ-HOR3', 'HorarioNombre' => 'Horario ZZ 3', 'EessId' => $inactivo]))
            ->assertStatus(422)->assertJsonValidationErrors(['EessId']);
    }

    // ------------------------------------------------------------------ Parametro de jornada

    public function test_las_vigencias_de_una_jornada_no_se_superponen_pero_pueden_ser_consecutivas(): void
    {
        $spec = $this->spec('parametros-jornada');
        $this->crear($spec); // 2005-01-01 .. 2009-12-31

        // Consecutiva: empieza al dia siguiente.
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'ParametroJornadaVigenciaDesde' => '2010-01-01', 'ParametroJornadaVigenciaHasta' => '2014-12-31',
        ]))->assertCreated();
        // Se solapa con la primera.
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'ParametroJornadaVigenciaDesde' => '2009-06-01', 'ParametroJornadaVigenciaHasta' => '2010-06-30',
        ]))->assertStatus(422)->assertJsonValidationErrors(['ParametroJornadaVigenciaDesde']);
        // Un parametro inactivo no cuenta para la superposicion.
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'ParametroJornadaVigenciaDesde' => '2009-06-01', 'ParametroJornadaVigenciaHasta' => '2010-06-30', 'ParametroJornadaEstado' => false,
        ]))->assertCreated();
    }

    public function test_filtro_de_parametros_por_fecha_de_vigencia(): void
    {
        $guardia = $this->id('Configuracion.TipoJornada', 'TipoJornadaId', ['TipoJornadaCodigo' => 'GUARDIA']);

        $vigente = $this->getJson("/api/parametros-jornada?tipo_jornada_id={$guardia}&vigente_en=2026-09-30")->assertOk();
        $this->assertSame(['2020-01-01'], array_column($vigente->json('data'), 'vigencia_desde'));
        $historico = $this->getJson("/api/parametros-jornada?tipo_jornada_id={$guardia}&vigente_en=2016-01-01")->assertOk();
        $this->assertSame(['2015-01-01'], array_column($historico->json('data'), 'vigencia_desde'));
        $this->assertEquals(12, $vigente->json('data.0.horas_diarias'));
    }

    // ------------------------------------------------------------------ Tramo de tolerancia

    public function test_la_escala_sembrada_es_la_del_art_22_del_rit(): void
    {
        $general = $this->id('Configuracion.TablaTolerancia', 'TablaToleranciaId', ['TablaToleranciaCodigo' => 'RIT_GENERAL']);
        $tramos = $this->getJson("/api/tramos-tolerancia?tabla_tolerancia_id={$general}&tipo=TARDANZA")->assertOk()->json('data');

        // [desde, hasta, minutos de descuento, es inasistencia]
        $this->assertSame(
            [[1, 5, 0, false], [6, 10, 10, false], [11, 20, 20, false], [21, 30, 30, false], [31, null, null, true]],
            array_map(fn ($t) => [$t['minutos_desde'], $t['minutos_hasta'], $t['minutos_descuento'], $t['es_inasistencia']], $tramos),
        );
        $salida = $this->getJson("/api/tramos-tolerancia?tabla_tolerancia_id={$general}&tipo=SALIDA_ANTICIPADA")->assertOk()->json('data');
        $this->assertCount(1, $salida);
        $this->assertTrue($salida[0]['es_inasistencia']);
    }

    public function test_los_tramos_de_una_escala_no_se_superponen(): void
    {
        $spec = $this->spec('tramos-tolerancia');
        $tramo = fn (int $desde, ?int $hasta, array $extra = []) => $this->payload($spec, [
            'TramoToleranciaMinutosDesde' => $desde, 'TramoToleranciaMinutosHasta' => $hasta,
        ] + $extra);

        $this->postJson($spec['endpoint'], $tramo(1, 5))->assertCreated();
        $segundo = $this->postJson($spec['endpoint'], $tramo(6, 10, ['TramoToleranciaMinutosDescuento' => 10]))->assertCreated()->json('data.id');
        $this->postJson($spec['endpoint'], $tramo(3, 8))->assertStatus(422)->assertJsonValidationErrors(['TramoToleranciaMinutosDesde']);
        $this->postJson($spec['endpoint'], $tramo(11, null, ['TramoToleranciaEsInasistencia' => true]))->assertCreated();
        // Hay un tramo abierto desde el minuto 11: nada puede empezar despues.
        $this->postJson($spec['endpoint'], $tramo(20, 25))->assertStatus(422);
        // Ensanchar el segundo hasta 12 lo haria chocar con el abierto.
        $this->patchJson("{$spec['endpoint']}/{$segundo}", ['TramoToleranciaMinutosHasta' => 12])->assertStatus(422);
        $this->patchJson("{$spec['endpoint']}/{$segundo}", ['TramoToleranciaMinutosHasta' => 10, 'TramoToleranciaDescripcion' => 'ok'])->assertOk();
        // Otro tipo en la misma escala es independiente.
        $this->postJson($spec['endpoint'], $tramo(1, 5, ['TramoToleranciaTipo' => 'SALIDA_ANTICIPADA']))->assertCreated();
    }

    // ------------------------------------------------------------------ Motivo de papeleta

    public function test_filtro_de_motivos_por_tipo_de_papeleta(): void
    {
        $tipo = $this->id('Solicitudes.TipoPapeleta', 'TipoPapeletaId', ['TipoPapeletaCodigo' => 'COMISION']);

        $r = $this->getJson("/api/motivos-papeleta?tipo_papeleta_id={$tipo}&por_pagina=100")->assertOk();
        $this->assertGreaterThanOrEqual(4, count($r->json('data')));
        $this->assertSame([$tipo], array_values(array_unique(array_column($r->json('data'), 'tipo_papeleta_id'))));
        $this->getJson('/api/motivos-papeleta?estado=0')->assertOk()->assertJsonPath('data.0.codigo', 'LAC_ANTIGUO');
    }

    // ------------------------------------------------------------------ Rol <-> Permiso

    public function test_el_listado_de_asignaciones_se_filtra_por_rol_y_modulo(): void
    {
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'TRABAJADOR']);

        $r = $this->getJson("/api/roles-permisos?rol_id={$rol}&por_pagina=100")->assertOk();
        $this->assertSame(['ASISTENCIA_VER', 'SOLICITUDES_VER'], array_column(array_column($r->json('data'), 'permiso'), 'codigo'));
        $this->getJson('/api/roles-permisos?modulo=SEGURIDAD&por_pagina=100')->assertOk()->assertJsonPath('data.0.permiso.modulo', 'SEGURIDAD');
    }

    public function test_no_se_asigna_un_permiso_inactivo_ni_a_un_rol_inactivo(): void
    {
        $inactivo = $this->id('Seguridad.Permiso', 'PermisoId', ['PermisoCodigo' => 'LEGADO_IMPORTAR']);
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'PORTERIA']);

        $this->postJson('/api/roles-permisos', ['RolId' => $rol, 'PermisoId' => $inactivo])
            ->assertStatus(422)->assertJsonValidationErrors(['PermisoId']);
    }

    public function test_sincronizar_los_permisos_de_un_rol_agrega_quita_y_reactiva(): void
    {
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'PORTERIA']); // sembrado con SOLICITUDES_VER y SOLICITUDES_EDITAR
        $ids = fn (array $codigos) => array_map(fn ($c) => $this->id('Seguridad.Permiso', 'PermisoId', ['PermisoCodigo' => $c]), $codigos);
        $asignados = fn () => DB::table('Seguridad.RolPermiso')->where('RolId', $rol)->where('RolPermisoEstado', 1)
            ->join('Seguridad.Permiso as p', 'p.PermisoId', '=', 'Seguridad.RolPermiso.PermisoId')->orderBy('p.PermisoCodigo')->pluck('p.PermisoCodigo')->all();

        $this->assertSame(['SOLICITUDES_EDITAR', 'SOLICITUDES_VER'], $asignados());

        // Quita SOLICITUDES_EDITAR, deja SOLICITUDES_VER y agrega dos.
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => $ids(['SOLICITUDES_VER', 'ASISTENCIA_VER', 'REPORTES_VER'])])
            ->assertOk()->assertJsonPath('asignados', 3)->assertJsonPath('quitados', 1);
        $this->assertSame(['ASISTENCIA_VER', 'REPORTES_VER', 'SOLICITUDES_VER'], $asignados());

        // Un permiso inactivo en la asignacion se reactiva en vez de duplicarse.
        $reportes = $this->id('Seguridad.Permiso', 'PermisoId', ['PermisoCodigo' => 'REPORTES_VER']);
        DB::table('Seguridad.RolPermiso')->where(['RolId' => $rol, 'PermisoId' => $reportes])->update(['RolPermisoEstado' => 0]);
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => $ids(['ASISTENCIA_VER', 'REPORTES_VER'])])->assertOk();
        $this->assertSame(['ASISTENCIA_VER', 'REPORTES_VER'], $asignados());
        $this->assertSame(2, DB::table('Seguridad.RolPermiso')->where('RolId', $rol)->count());

        // Lista vacia = el rol queda sin permisos.
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => []])->assertOk()->assertJsonPath('asignados', 0);
        $this->assertSame([], $asignados());
        $this->getJson("/api/roles/{$rol}/permisos")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sincronizar_valida_la_lista_y_el_rol(): void
    {
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'PORTERIA']);
        $antes = DB::table('Seguridad.RolPermiso')->where('RolId', $rol)->count();

        $this->putJson("/api/roles/{$rol}/permisos", [])->assertStatus(422)->assertJsonValidationErrors(['PermisoIds']);
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => 'todos'])->assertStatus(422)->assertJsonValidationErrors(['PermisoIds']);
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => [999999]])->assertStatus(422)->assertJsonValidationErrors(['PermisoIds.0']);
        $this->putJson("/api/roles/{$rol}/permisos", ['PermisoIds' => [1, 1]])->assertStatus(422)->assertJsonValidationErrors(['PermisoIds.0']);
        $this->putJson('/api/roles/999999/permisos', ['PermisoIds' => []])->assertNotFound();
        $this->getJson('/api/roles/999999/permisos')->assertNotFound();

        $this->assertSame($antes, DB::table('Seguridad.RolPermiso')->where('RolId', $rol)->count(), 'Una peticion invalida no debe tocar nada.');
    }

    public function test_el_listado_de_roles_no_se_confunde_con_las_rutas_anidadas(): void
    {
        $this->getJson('/api/roles?por_pagina=100')->assertOk()->assertJsonStructure(['data' => [['id', 'codigo']]]);
        $rol = $this->id('Seguridad.Rol', 'RolId', ['RolCodigo' => 'ADMIN']);
        $this->getJson("/api/roles/{$rol}")->assertOk()->assertJsonPath('data.codigo', 'ADMIN');
    }
}
