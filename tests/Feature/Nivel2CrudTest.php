<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Nivel2Modulos;

/**
 * Modulos de Nivel 2 (8 tablas que dependen de un modulo de Nivel 1): VinculoLaboral, Usuario, HorarioDetalle,
 * Colegiatura, PlantillaBiometrica, ConsentimientoBiometrico, AutorizacionMetodo y OcurrenciaPorteria. Los casos
 * genericos vienen de CrudModulosTestCase; aqui van las reglas propias de cada modulo (RIT incluido).
 */
class Nivel2CrudTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel2Modulos::all();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    private function metodo(string $codigo): int
    {
        return $this->id('Biometria.MetodoMarcacion', 'MetodoMarcacionId', ['MetodoMarcacionCodigo' => $codigo]);
    }

    // ================================================================== Vinculo laboral

    public function test_las_condiciones_que_exigen_airhsp_no_admiten_un_vinculo_sin_el(): void
    {
        $spec = $this->spec('vinculos-laborales');
        $nombrado = $this->id('Personal.CondicionLaboral', 'CondicionLaboralId', ['CondicionLaboralCodigo' => 'NOMBRADO']);

        $this->postJson($spec['endpoint'], $this->payload($spec, ['CondicionLaboralId' => $nombrado, 'VinculoLaboralCodigoAirhsp' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['VinculoLaboralCodigoAirhsp']);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['CondicionLaboralId' => $nombrado]))
            ->assertCreated()->assertJsonPath('data.condicion.requiere_airhsp', true);
        // La que no lo exige se guarda sin codigo.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['VinculoLaboralCodigo' => 'ZZ-VL2', 'VinculoLaboralCodigoAirhsp' => null, 'TrabajadorId' => $this->nuevoTrabajador()]))
            ->assertCreated();
    }

    public function test_un_trabajador_no_puede_tener_dos_vinculos_activos_que_se_superpongan(): void
    {
        $spec = $this->spec('vinculos-laborales');
        $primero = $this->crear($spec, ['VinculoLaboralFechaFin' => '2026-06-30']);   // 2026-01-01 .. 2026-06-30

        $otro = fn (string $codigo, string $inicio, ?string $fin, array $extra = []) => $this->payload($spec, [
            'VinculoLaboralCodigo' => $codigo, 'VinculoLaboralCodigoAirhsp' => null,
            'VinculoLaboralFechaInicio' => $inicio, 'VinculoLaboralFechaFin' => $fin,
        ] + $extra);

        // Se superpone con el primero (RIT Art. 86: doble percepcion).
        $this->postJson($spec['endpoint'], $otro('ZZ-S1', '2026-06-15', null))
            ->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
        $this->postJson($spec['endpoint'], $otro('ZZ-S2', '2025-12-01', '2026-01-01'))->assertStatus(422);
        // Consecutivo: empieza el dia siguiente.
        $segundo = $this->postJson($spec['endpoint'], $otro('ZZ-S3', '2026-07-01', null))->assertCreated()->json('data.id');
        // Ahora hay uno abierto: no se admite otro abierto ni uno posterior.
        $this->postJson($spec['endpoint'], $otro('ZZ-S4', '2027-01-01', null))->assertStatus(422);
        // Uno INACTIVO no cuenta para la superposicion...
        $inactivo = $this->postJson($spec['endpoint'], $otro('ZZ-S5', '2026-03-01', '2026-04-01', ['VinculoLaboralEstado' => false]))
            ->assertCreated()->json('data.id');
        // ...pero no se puede reactivar si choca con uno activo.
        $this->patchJson("{$spec['endpoint']}/{$inactivo}", ['VinculoLaboralEstado' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
        // Cerrar el primero con una fecha que invade al segundo tambien se rechaza.
        $this->patchJson("{$spec['endpoint']}/{$primero}", ['VinculoLaboralFechaFin' => '2026-07-15'])->assertStatus(422);
        $this->assertNotNull($segundo);
    }

    public function test_el_vinculo_calcula_si_esta_vigente_y_se_filtra(): void
    {
        $spec = $this->spec('vinculos-laborales');
        $futuro = $this->crear($spec, ['VinculoLaboralFechaInicio' => '2999-01-01']);
        $cesado = $this->crear($spec, [
            'VinculoLaboralCodigo' => 'ZZ-VL-C', 'VinculoLaboralCodigoAirhsp' => null, 'TrabajadorId' => $this->nuevoTrabajador(),
            'VinculoLaboralFechaInicio' => '2020-01-01', 'VinculoLaboralFechaFin' => '2021-12-31', 'VinculoLaboralMotivoCese' => 'Fin de contrato',
        ]);

        $this->getJson("{$spec['endpoint']}/{$futuro}")->assertJsonPath('data.vigente', false)->assertJsonPath('data.activo', true);
        $this->getJson("{$spec['endpoint']}/{$cesado}")->assertJsonPath('data.vigente', false)->assertJsonPath('data.motivo_cese', 'Fin de contrato');

        $vigentes = $this->getJson("{$spec['endpoint']}?vigente=1&por_pagina=100")->assertOk();
        $this->assertNotContains($cesado, array_column($vigentes->json('data'), 'id'));
        $this->assertTrue(collect($vigentes->json('data'))->every(fn ($v) => $v['vigente']));
        $noVigentes = $this->getJson("{$spec['endpoint']}?vigente=0&por_pagina=100")->assertOk();
        $this->assertContains($cesado, array_column($noVigentes->json('data'), 'id'));
    }

    public function test_busqueda_y_filtros_de_vinculos(): void
    {
        $this->getJson('/api/vinculos-laborales?buscar=Quispe')->assertOk()->assertJsonPath('data.0.trabajador.nombre_completo', 'Quispe Huamán, María Elena');
        $this->getJson('/api/vinculos-laborales?buscar=100002')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', 'VL-0002');
        $this->getJson('/api/vinculos-laborales?buscar=VL-0003')->assertOk()->assertJsonCount(2, 'data');   // el contrato anterior y el CAS vigente
        $eess = $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'SEDE-RRHH']);
        $r = $this->getJson("/api/vinculos-laborales?eess_id={$eess}&por_pagina=100")->assertOk();
        $this->assertSame([$eess], array_values(array_unique(array_column($r->json('data'), 'eess_id'))));
        $cas = $this->id('Personal.CondicionLaboral', 'CondicionLaboralId', ['CondicionLaboralCodigo' => 'CAS']);
        $this->getJson("/api/vinculos-laborales?condicion_laboral_id={$cas}&vigente=1&por_pagina=100")->assertOk()->assertJsonPath('data.0.condicion.nombre', 'CAS');
        $microred = $this->id('Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-LE']);
        $this->assertGreaterThan(0, count($this->getJson("/api/vinculos-laborales?microred_id={$microred}&por_pagina=100")->json('data')));
    }

    public function test_los_datos_sembrados_de_vinculos_cumplen_las_reglas(): void
    {
        // Ningun trabajador tiene dos vinculos activos superpuestos y los que exigen AIRHSP lo traen.
        $filas = DB::table('Personal.VinculoLaboral as v')
            ->join('Personal.CondicionLaboral as c', 'c.CondicionLaboralId', '=', 'v.CondicionLaboralId')
            ->where('v.VinculoLaboralEstado', 1)
            ->get(['v.TrabajadorId', 'v.VinculoLaboralFechaInicio as i', 'v.VinculoLaboralFechaFin as f', 'v.VinculoLaboralCodigoAirhsp as a', 'c.CondicionLaboralRequiereAirhsp as r']);

        foreach ($filas as $fila) {
            $this->assertFalse((bool) $fila->r && blank($fila->a), 'Vinculo sembrado sin AIRHSP pese a que la condicion lo exige.');
        }
        foreach ($filas->groupBy('TrabajadorId') as $delTrabajador) {
            foreach ($delTrabajador as $x => $a) {
                foreach ($delTrabajador->slice($x + 1) as $b) {
                    $solapan = $a->i <= ($b->f ?? '9999-12-31') && $b->i <= ($a->f ?? '9999-12-31');
                    $this->assertFalse($solapan, 'Vinculos sembrados superpuestos.');
                }
            }
        }
    }

    // ================================================================== Usuario

    public function test_la_contrasena_se_guarda_como_hash_y_nunca_se_devuelve(): void
    {
        $spec = $this->spec('usuarios');
        $r = $this->postJson($spec['endpoint'], $this->payload($spec, ['UsuarioNombre' => 'Zz.Mayusculas']))->assertCreated();

        $r->assertJsonPath('data.nombre', 'zz.mayusculas');   // el usuario se normaliza a minusculas
        $this->assertStringNotContainsString('Clave2026', $r->getContent());
        $this->assertStringNotContainsString('password', strtolower($r->getContent()));
        $this->assertStringNotContainsString('$2y$', $r->getContent());

        $hash = DB::table($spec['table'])->where($spec['pk'], $r->json('data.id'))->value('UsuarioPasswordHash');
        $this->assertNotSame('Clave2026', $hash);
        $this->assertTrue(Hash::check('Clave2026', $hash));
        $this->getJson("{$spec['endpoint']}/{$r->json('data.id')}")->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.UsuarioPasswordHash');
        $this->getJson("{$spec['endpoint']}?por_pagina=100")->assertOk();
        $this->assertStringNotContainsString('$2y$', $this->getJson("{$spec['endpoint']}?por_pagina=100")->getContent());
    }

    public function test_la_contrasena_solo_cambia_si_se_envia_una_nueva(): void
    {
        $spec = $this->spec('usuarios');
        $id = $this->crear($spec);
        $original = DB::table($spec['table'])->where($spec['pk'], $id)->value('UsuarioPasswordHash');

        // Editar otros datos (el formulario no reenvia la contrasena) la deja igual.
        $this->patchJson("{$spec['endpoint']}/{$id}", ['UsuarioCorreo' => 'nuevo@ejemplo.pe', 'UsuarioPassword' => null, 'UsuarioPasswordConfirmacion' => null])->assertOk();
        $this->assertSame($original, DB::table($spec['table'])->where($spec['pk'], $id)->value('UsuarioPasswordHash'));

        // Con contrasena nueva cambia, y exige confirmarla.
        $this->patchJson("{$spec['endpoint']}/{$id}", ['UsuarioPassword' => 'NuevaClave2026'])->assertStatus(422)->assertJsonValidationErrors(['UsuarioPasswordConfirmacion']);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['UsuarioPassword' => 'NuevaClave2026', 'UsuarioPasswordConfirmacion' => 'NuevaClave2026'])->assertOk();
        $nuevo = DB::table($spec['table'])->where($spec['pk'], $id)->value('UsuarioPasswordHash');
        $this->assertNotSame($original, $nuevo);
        $this->assertTrue(Hash::check('NuevaClave2026', $nuevo));
    }

    public function test_cada_trabajador_tiene_una_sola_cuenta_y_el_usuario_no_distingue_mayusculas(): void
    {
        $spec = $this->spec('usuarios');
        $this->crear($spec);

        // Otro trabajador con el mismo usuario en mayusculas: choca (el UNIQUE de SQL Server no distingue mayusculas).
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $this->nuevoTrabajador(), 'UsuarioNombre' => 'ZZ.USUARIO', 'UsuarioCorreo' => 'otro@ejemplo.pe']))
            ->assertStatus(422)->assertJsonValidationErrors(['UsuarioNombre']);
        // El mismo trabajador con otro usuario: ya tiene cuenta.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['UsuarioNombre' => 'zz.otro', 'UsuarioCorreo' => 'otro@ejemplo.pe']))
            ->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
    }

    public function test_busqueda_de_usuarios_y_datos_sembrados(): void
    {
        $this->getJson('/api/usuarios?buscar=mquispe')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.trabajador.numero_documento', '70000001');
        $this->getJson('/api/usuarios?buscar=Gutiérrez')->assertOk()->assertJsonPath('data.0.nombre', 'pgutierrez');
        $this->getJson('/api/usuarios?estado=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'jretirado');
        // La contrasena de prueba sembrada es "Prueba2026*".
        $this->assertTrue(Hash::check('Prueba2026*', DB::table('Seguridad.Usuario')->where('UsuarioNombre', 'mquispe')->value('UsuarioPasswordHash')));
    }

    // ================================================================== Detalle de horario

    public function test_agregar_un_turno_sin_orden_lo_ubica_despues_de_los_del_dia(): void
    {
        $spec = $this->spec('horarios-detalle');
        $manana = $this->id('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'M']);   // HOR-ESS-M ya trabaja M de lunes a sabado

        $r = $this->postJson($spec['endpoint'], $this->payload($spec, ['HorarioDetalleDia' => 1, 'HorarioDetalleOrden' => null]))->assertCreated();
        $r->assertJsonPath('data.orden', 2)->assertJsonPath('data.dia_nombre', 'Lunes')->assertJsonPath('data.turno.hora_entrada', '13:30');

        // Dos turnos del mismo dia no pueden tener el mismo orden.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['HorarioDetalleDia' => 2, 'TurnoId' => $manana + 100, 'HorarioDetalleOrden' => 1]))->assertStatus(422);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['HorarioDetalleDia' => 3, 'HorarioDetalleOrden' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors(['HorarioDetalleOrden']);
    }

    public function test_sincronizar_reemplaza_la_grilla_semanal_completa(): void
    {
        $horario = $this->id('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-ESS-M']);
        $m = $this->id('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'M']);
        $t = $this->id('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'T']);

        $this->assertSame(6, $this->getJson("/api/horarios/{$horario}/detalle")->assertOk()->json('data') ? count($this->getJson("/api/horarios/{$horario}/detalle")->json('data')) : 0);

        $grilla = [
            ['TurnoId' => $t, 'HorarioDetalleDia' => 1],                       // llega primero pero entra despues (13:30)
            ['TurnoId' => $m, 'HorarioDetalleDia' => 1],
            ['TurnoId' => $m, 'HorarioDetalleDia' => 2, 'HorarioDetalleEsDescanso' => true],
            ['TurnoId' => $t, 'HorarioDetalleDia' => 7, 'HorarioDetalleOrden' => 4],
        ];
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => $grilla])->assertOk()->assertJsonPath('filas', 4);

        $filas = collect($this->getJson("/api/horarios/{$horario}/detalle")->json('data'));
        $this->assertCount(4, $filas);
        // Sin orden explicito: se ordenan por hora de entrada (M 07:30 antes que T 13:30).
        $lunes = $filas->where('dia', 1)->sortBy('orden')->pluck('turno.codigo')->values()->all();
        $this->assertSame(['M', 'T'], $lunes);
        $this->assertTrue($filas->firstWhere('dia', 2)['es_descanso']);
        $this->assertSame(4, $filas->firstWhere('dia', 7)['orden']);

        // Vacio = horario sin dias asignados.
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => []])->assertOk()->assertJsonPath('filas', 0);
        $this->assertCount(0, $this->getJson("/api/horarios/{$horario}/detalle")->json('data'));
    }

    public function test_sincronizar_valida_antes_de_tocar_nada(): void
    {
        $horario = $this->id('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-ESS-M']);
        $m = $this->id('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'M']);
        $antes = DB::table('Configuracion.HorarioDetalle')->where('HorarioId', $horario)->count();

        $this->putJson("/api/horarios/{$horario}/detalle", [])->assertStatus(422)->assertJsonValidationErrors(['Detalle']);
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => [['TurnoId' => 999999, 'HorarioDetalleDia' => 1]]])->assertStatus(422)->assertJsonValidationErrors(['Detalle.0.TurnoId']);
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => [['TurnoId' => $m, 'HorarioDetalleDia' => 9]]])->assertStatus(422)->assertJsonValidationErrors(['Detalle.0.HorarioDetalleDia']);
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => [['TurnoId' => $m, 'HorarioDetalleDia' => 1], ['TurnoId' => $m, 'HorarioDetalleDia' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['Detalle.1.TurnoId']);
        $this->putJson("/api/horarios/{$horario}/detalle", ['Detalle' => [
            ['TurnoId' => $m, 'HorarioDetalleDia' => 1, 'HorarioDetalleOrden' => 1],
            ['TurnoId' => $this->id('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'T']), 'HorarioDetalleDia' => 1, 'HorarioDetalleOrden' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors(['Detalle.1.HorarioDetalleOrden']);
        $this->putJson('/api/horarios/999999/detalle', ['Detalle' => []])->assertNotFound();

        // Un horario inactivo no se modifica.
        $inactivo = $this->id('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-FM-OLD']);
        $this->putJson("/api/horarios/{$inactivo}/detalle", ['Detalle' => []])->assertStatus(422)->assertJsonValidationErrors(['Detalle']);

        $this->assertSame($antes, DB::table('Configuracion.HorarioDetalle')->where('HorarioId', $horario)->count(), 'Una peticion invalida no debe tocar nada.');
    }

    public function test_los_horarios_siguen_resolviendose_aunque_haya_rutas_anidadas(): void
    {
        $this->getJson('/api/horarios?por_pagina=100')->assertOk()->assertJsonStructure(['data' => [['id', 'codigo']]]);
        $horario = $this->id('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-ADM-LV']);
        $this->getJson("/api/horarios/{$horario}")->assertOk()->assertJsonPath('data.codigo', 'HOR-ADM-LV');
        $this->assertCount(5, $this->getJson("/api/horarios/{$horario}/detalle")->json('data'));
    }

    // ================================================================== Colegiatura

    public function test_el_colegio_debe_corresponder_a_la_profesion_del_trabajador(): void
    {
        $spec = $this->spec('colegiaturas');
        $cmp = $this->id('Personal.ColegiaturaTipo', 'ColegiaturaTipoId', ['ColegiaturaTipoCodigo' => 'CMP']);

        // El trabajador de prueba es enfermero: no puede tener un CMP (Colegio Medico).
        $this->postJson($spec['endpoint'], $this->payload($spec, ['ColegiaturaTipoId' => $cmp]))
            ->assertStatus(422)->assertJsonValidationErrors(['ColegiaturaTipoId']);
        // Sin profesion registrada, no hay contra que comparar.
        $sinProfesion = $this->nuevoTrabajador();
        $this->postJson($spec['endpoint'], $this->payload($spec, ['ColegiaturaTipoId' => $cmp, 'TrabajadorId' => $sinProfesion]))->assertCreated();
    }

    public function test_solo_una_colegiatura_principal_activa_por_trabajador(): void
    {
        $spec = $this->spec('colegiaturas');
        $this->crear($spec);   // CEP principal

        $ctsp = $this->id('Personal.ColegiaturaTipo', 'ColegiaturaTipoId', ['ColegiaturaTipoCodigo' => 'CTSP']);
        $otra = fn (bool $principal) => $this->payload($spec, [
            'ColegiaturaTipoId' => $ctsp, 'ColegiaturaNumero' => 'ZZ0002', 'ColegiaturaEsPrincipal' => $principal,
            'TrabajadorId' => $this->nuevoTrabajador(),
        ]);

        // Ese colegio es de otra profesion, asi que para este caso se usa un trabajador sin profesion.
        $trabajador = $this->nuevoTrabajador();
        $base = fn (bool $principal, string $numero) => $this->payload($spec, [
            'ColegiaturaTipoId' => $ctsp, 'ColegiaturaNumero' => $numero, 'ColegiaturaEsPrincipal' => $principal, 'TrabajadorId' => $trabajador,
        ]);
        $this->postJson($spec['endpoint'], $base(true, 'ZZ0002'))->assertCreated();
        $cep = $this->id('Personal.ColegiaturaTipo', 'ColegiaturaTipoId', ['ColegiaturaTipoCodigo' => 'CEP']);
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'ColegiaturaTipoId' => $cep, 'ColegiaturaNumero' => 'ZZ0003', 'ColegiaturaEsPrincipal' => true, 'TrabajadorId' => $trabajador,
        ]))->assertStatus(422)->assertJsonValidationErrors(['ColegiaturaEsPrincipal']);
        // La misma colegiatura no principal si se puede, y despues no se puede promover a principal.
        $id = $this->postJson($spec['endpoint'], $this->payload($spec, [
            'ColegiaturaTipoId' => $cep, 'ColegiaturaNumero' => 'ZZ0003', 'ColegiaturaEsPrincipal' => false, 'TrabajadorId' => $trabajador,
        ]))->assertCreated()->json('data.id');
        $this->patchJson("{$spec['endpoint']}/{$id}", ['ColegiaturaEsPrincipal' => true])->assertStatus(422);
        $this->assertNotNull($otra);
    }

    public function test_una_colegiatura_no_se_repite_por_colegio_y_numero(): void
    {
        $spec = $this->spec('colegiaturas');
        $this->crear($spec);

        // Mismo colegio y numero para OTRO trabajador enfermero.
        $this->postJson($spec['endpoint'], $this->payload($spec, [
            'TrabajadorId' => $this->nuevoTrabajador(['ProfesionId' => $this->id('Personal.Profesion', 'ProfesionId', ['ProfesionCodigo' => 'ENFERMERIA'])]),
        ]))->assertStatus(422)->assertJsonValidationErrors(['ColegiaturaNumero']);
    }

    public function test_vigente_y_vencida_se_calculan_con_la_habilitacion_y_el_vencimiento(): void
    {
        $spec = $this->spec('colegiaturas');
        $vigente = $this->postJson($spec['endpoint'], $this->payload($spec, ['ColegiaturaFechaVencimiento' => now()->addYear()->toDateString()]))->assertCreated();
        $vigente->assertJsonPath('data.vigente', true)->assertJsonPath('data.vencida', false);

        $id = $vigente->json('data.id');
        $this->patchJson("{$spec['endpoint']}/{$id}", ['ColegiaturaFechaVencimiento' => now()->subDay()->toDateString()])->assertOk()
            ->assertJsonPath('data.vencida', true)->assertJsonPath('data.vigente', false);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['ColegiaturaFechaVencimiento' => null, 'ColegiaturaEsHabilitado' => false])->assertOk()
            ->assertJsonPath('data.vencida', false)->assertJsonPath('data.vigente', false);

        // Datos sembrados: la del contador esta vencida y la de la psicologa no habilitada.
        $this->getJson('/api/colegiaturas?buscar=7654')->assertOk()->assertJsonPath('data.0.vencida', true);
        $this->getJson('/api/colegiaturas?es_habilitado=0')->assertOk()->assertJsonPath('data.0.numero', '12987');
        $this->assertGreaterThan(0, count($this->getJson('/api/colegiaturas?vencida=1')->json('data')));
    }

    public function test_la_colegiatura_se_vincula_a_su_documento_de_sustento(): void
    {
        $spec = $this->spec('colegiaturas');
        $documento = $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'constancia-capacitación-0004.pdf']);

        $this->postJson($spec['endpoint'], $this->payload($spec, ['DocumentoSustentoId' => $documento]))
            ->assertCreated()->assertJsonPath('data.documento.nombre', 'constancia-capacitación-0004.pdf');
    }

    // ================================================================== Biometria

    public function test_no_se_registra_una_plantilla_sin_consentimiento_vigente(): void
    {
        $spec = $this->spec('plantillas-biometricas');
        $sin = $this->nuevoTrabajador();
        $revocado = $this->conConsentimiento($this->nuevoTrabajador(), false);

        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $sin]))->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $revocado]))->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
        // Aceptar despues de haber revocado deja un consentimiento vigente.
        $this->conConsentimiento($revocado, true);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $revocado]))->assertCreated();
    }

    public function test_la_huella_requiere_autorizacion_de_metodo_vigente_y_no_se_repite_el_dedo(): void
    {
        $spec = $this->spec('plantillas-biometricas');
        $trabajador = $this->conConsentimiento($this->nuevoTrabajador());
        $huella = fn (string $dedo = 'INDICE_DERECHO') => $this->payload($spec, [
            'TrabajadorId' => $trabajador, 'PlantillaBiometricaTipo' => 'HUELLA', 'PlantillaBiometricaDedo' => $dedo,
        ]);

        // El RIT fija el reconocimiento facial como unica forma de marcar: la huella exige autorizacion.
        $this->postJson($spec['endpoint'], $huella())->assertStatus(422)->assertJsonValidationErrors(['PlantillaBiometricaTipo']);

        $autorizacion = DB::table('Biometria.AutorizacionMetodo')->insertGetId([
            'TrabajadorId' => $trabajador, 'MetodoMarcacionId' => $this->metodo('HUELLA'), 'AutorizacionMetodoFechaInicio' => '2026-01-01',
        ], 'AutorizacionMetodoId');
        $this->postJson($spec['endpoint'], $huella())->assertCreated()->assertJsonPath('data.dedo', 'INDICE_DERECHO');
        $this->postJson($spec['endpoint'], $huella())->assertStatus(422)->assertJsonValidationErrors(['PlantillaBiometricaDedo']);
        $this->postJson($spec['endpoint'], $huella('PULGAR_DERECHO'))->assertCreated();

        // Una autorizacion vencida ya no habilita.
        DB::table('Biometria.AutorizacionMetodo')->where('AutorizacionMetodoId', $autorizacion)->update(['AutorizacionMetodoFechaFin' => '2026-01-31']);
        $this->postJson($spec['endpoint'], $huella('MEDIO_DERECHO'))->assertStatus(422)->assertJsonValidationErrors(['PlantillaBiometricaTipo']);
    }

    public function test_la_referencia_biometrica_se_guarda_como_binario_y_nunca_se_devuelve(): void
    {
        $spec = $this->spec('plantillas-biometricas');
        $bytes = random_bytes(300);

        $r = $this->postJson($spec['endpoint'], $this->payload($spec, ['PlantillaBiometricaReferencia' => base64_encode($bytes)]))->assertCreated();
        $id = $r->json('data.id');

        $r->assertJsonPath('data.tiene_referencia', true)->assertJsonPath('data.referencia_bytes', 300);
        $this->assertStringNotContainsString(base64_encode($bytes), $r->getContent());
        $this->assertStringNotContainsString('PlantillaBiometricaReferencia', $r->getContent());

        // En la base quedaron EXACTAMENTE esos bytes.
        $guardado = DB::selectOne('SELECT CONVERT(VARCHAR(MAX), PlantillaBiometricaReferencia, 2) AS hex, DATALENGTH(PlantillaBiometricaReferencia) AS bytes FROM Biometria.PlantillaBiometrica WHERE PlantillaBiometricaId = ?', [$id]);
        $this->assertSame(300, (int) $guardado->bytes);
        $this->assertSame(strtolower(bin2hex($bytes)), strtolower($guardado->hex));

        // Ni el listado ni el detalle la exponen; editar otros datos no la borra; enviar una nueva la reemplaza.
        foreach (["{$spec['endpoint']}?por_pagina=100", "{$spec['endpoint']}/{$id}"] as $url) {
            $this->assertStringNotContainsString(base64_encode($bytes), $this->getJson($url)->getContent());
        }
        $this->patchJson("{$spec['endpoint']}/{$id}", ['PlantillaBiometricaEstado' => true])->assertOk()->assertJsonPath('data.referencia_bytes', 300);
        $nueva = random_bytes(64);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['PlantillaBiometricaReferencia' => base64_encode($nueva)])->assertOk()->assertJsonPath('data.referencia_bytes', 64);
        $this->assertSame(64, (int) DB::selectOne('SELECT DATALENGTH(PlantillaBiometricaReferencia) AS b FROM Biometria.PlantillaBiometrica WHERE PlantillaBiometricaId = ?', [$id])->b);

        // Sin referencia: pendiente de enrolamiento.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $this->conConsentimiento($this->nuevoTrabajador())]))
            ->assertCreated()->assertJsonPath('data.tiene_referencia', false)->assertJsonPath('data.referencia_bytes', null);
    }

    public function test_listado_de_plantillas_y_datos_sembrados(): void
    {
        $r = $this->getJson('/api/plantillas-biometricas?por_pagina=100')->assertOk();
        $this->assertCount(10, $r->json('data'));
        $this->getJson('/api/plantillas-biometricas?tipo=HUELLA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.trabajador.numero_documento', '70000002');
        $this->getJson('/api/plantillas-biometricas?estado=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.trabajador.numero_documento', '70000012');
        $this->getJson('/api/plantillas-biometricas?buscar=001234567')->assertOk()->assertJsonPath('data.0.tiene_referencia', false);
        $this->assertSame(0, DB::table('Biometria.PlantillaBiometrica')->where('PlantillaBiometricaEstado', 1)
            ->whereNotIn('TrabajadorId', DB::table('Biometria.ConsentimientoBiometrico')->where('ConsentimientoBiometricoAceptado', 1)->select('TrabajadorId'))->count());
    }

    // ------------------------------------------------------------------ Consentimiento (historial inmutable)

    public function test_registrar_un_consentimiento_y_su_historial_es_inmutable(): void
    {
        $trabajador = $this->nuevoTrabajador();
        $payload = ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => true, 'ConsentimientoBiometricoVersion' => 'v2.1'];

        $r = $this->postJson('/api/consentimientos-biometricos', $payload)->assertCreated();
        $r->assertJsonPath('data.aceptado', true)->assertJsonPath('data.version', 'v2.1')->assertJsonPath('data.vigente', true)
            ->assertJsonPath('data.trabajador.id', $trabajador);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r->json('data.fecha'));
        $id = $r->json('data.id');

        // La fecha del evento la pone el servidor: lo que mande el cliente se ignora.
        $manipulada = $this->postJson('/api/consentimientos-biometricos', $payload + ['ConsentimientoBiometricoFecha' => '2000-01-01 00:00:00'])->assertCreated();
        $this->assertStringStartsWith(now()->format('Y-m-d'), $manipulada->json('data.fecha'));

        // Historial: no se edita ni se borra.
        $this->putJson("/api/consentimientos-biometricos/{$id}", $payload)->assertStatus(405);
        $this->patchJson("/api/consentimientos-biometricos/{$id}", ['ConsentimientoBiometricoAceptado' => false])->assertStatus(405);
        $this->deleteJson("/api/consentimientos-biometricos/{$id}")->assertStatus(405);
        $this->assertSame(2, DB::table('Biometria.ConsentimientoBiometrico')->where('TrabajadorId', $trabajador)->count());

        // Solo el ultimo evento del trabajador es el vigente.
        $this->getJson("/api/consentimientos-biometricos/{$id}")->assertJsonPath('data.vigente', false);
        $this->getJson("/api/consentimientos-biometricos/{$manipulada->json('data.id')}")->assertJsonPath('data.vigente', true);
        $vigentes = $this->getJson("/api/consentimientos-biometricos?trabajador_id={$trabajador}&vigente=1")->assertOk();
        $this->assertSame([$manipulada->json('data.id')], array_column($vigentes->json('data'), 'id'));
    }

    public function test_validacion_del_consentimiento(): void
    {
        $url = '/api/consentimientos-biometricos';
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId', 'ConsentimientoBiometricoAceptado']);
        $trabajador = $this->nuevoTrabajador();
        $this->postJson($url, ['TrabajadorId' => $trabajador])->assertStatus(422)->assertJsonValidationErrors(['ConsentimientoBiometricoAceptado']);
        $this->postJson($url, ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => 'quizas'])->assertStatus(422);
        $this->postJson($url, ['TrabajadorId' => 999999, 'ConsentimientoBiometricoAceptado' => true])->assertStatus(422)->assertJsonValidationErrors(['TrabajadorId']);
        $this->postJson($url, ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => true, 'DocumentoSustentoId' => 999999])->assertStatus(422)->assertJsonValidationErrors(['DocumentoSustentoId']);
        $this->postJson($url, ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => true, 'ConsentimientoBiometricoVersion' => 'versión 1!'])->assertStatus(422)->assertJsonValidationErrors(['ConsentimientoBiometricoVersion']);
        $this->postJson($url, ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => true, 'ConsentimientoBiometricoVersion' => str_repeat('v', 31)])->assertStatus(422);
        $this->getJson("{$url}/999999999")->assertNotFound();
        $this->assertSame(0, DB::table('Biometria.ConsentimientoBiometrico')->where('TrabajadorId', $trabajador)->count());
    }

    public function test_revocar_el_consentimiento_desactiva_las_plantillas_del_trabajador(): void
    {
        $trabajador = $this->conConsentimiento($this->nuevoTrabajador());
        $spec = $this->spec('plantillas-biometricas');
        $a = $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $trabajador]))->assertCreated()->json('data.id');
        $b = $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $trabajador]))->assertCreated()->json('data.id');
        $otro = $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $this->conConsentimiento($this->nuevoTrabajador())]))->assertCreated()->json('data.id');

        $this->postJson('/api/consentimientos-biometricos', ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => false])
            ->assertCreated()->assertJsonPath('data.aceptado', false)->assertJsonPath('plantillas_desactivadas', 2);

        $this->getJson("{$spec['endpoint']}/{$a}")->assertJsonPath('data.activo', false);
        $this->getJson("{$spec['endpoint']}/{$b}")->assertJsonPath('data.activo', false);
        $this->getJson("{$spec['endpoint']}/{$otro}")->assertJsonPath('data.activo', true);   // otro trabajador: intacto
        // Y ya no se puede registrar otra mientras no vuelva a aceptar.
        $this->postJson($spec['endpoint'], $this->payload($spec, ['TrabajadorId' => $trabajador]))->assertStatus(422);
        // Aceptar de nuevo no reactiva las plantillas viejas: hay que enrolarlas otra vez.
        $this->postJson('/api/consentimientos-biometricos', ['TrabajadorId' => $trabajador, 'ConsentimientoBiometricoAceptado' => true])->assertCreated()->assertJsonPath('plantillas_desactivadas', 0);
        $this->getJson("{$spec['endpoint']}/{$a}")->assertJsonPath('data.activo', false);
    }

    public function test_listado_de_consentimientos_y_datos_sembrados(): void
    {
        $this->getJson('/api/consentimientos-biometricos?por_pagina=100')->assertOk()->assertJsonCount(11, 'data');
        $vigentes = $this->getJson('/api/consentimientos-biometricos?vigente=1&por_pagina=100')->assertOk();
        $this->assertCount(10, $vigentes->json('data'));   // 10 trabajadores con consentimiento; 70000012 tiene dos eventos
        $this->assertTrue(collect($vigentes->json('data'))->every(fn ($c) => $c['vigente']));
        // 70000012: acepto y luego revoco -> su consentimiento vigente es la revocacion.
        $r = $this->getJson('/api/consentimientos-biometricos?buscar=70000012&por_pagina=100')->assertOk();
        $this->assertSame([false, true], array_column($r->json('data'), 'aceptado') === [false, true] ? [false, true] : array_column($r->json('data'), 'aceptado'));
        $this->assertFalse(collect($r->json('data'))->firstWhere('vigente', true)['aceptado']);
        $this->getJson('/api/consentimientos-biometricos?aceptado=0&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------ Autorizacion de metodo

    public function test_la_autorizacion_calcula_su_vigencia(): void
    {
        $spec = $this->spec('autorizaciones-metodo');
        $id = $this->postJson($spec['endpoint'], $this->payload($spec, [
            'AutorizacionMetodoFechaInicio' => now()->subMonth()->toDateString(), 'AutorizacionMetodoFechaFin' => now()->addMonth()->toDateString(),
        ]))->assertCreated()->assertJsonPath('data.vigente', true)->json('data.id');

        $this->patchJson("{$spec['endpoint']}/{$id}", ['AutorizacionMetodoFechaFin' => now()->subDay()->toDateString()])->assertOk()->assertJsonPath('data.vigente', false);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['AutorizacionMetodoFechaFin' => null, 'AutorizacionMetodoFechaInicio' => now()->addDay()->toDateString()])->assertOk()->assertJsonPath('data.vigente', false);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['AutorizacionMetodoFechaInicio' => now()->subDay()->toDateString()])->assertOk()->assertJsonPath('data.vigente', true);
        $this->deleteJson("{$spec['endpoint']}/{$id}")->assertOk();
        $this->getJson("{$spec['endpoint']}/{$id}")->assertJsonPath('data.vigente', false)->assertJsonPath('data.activo', false);
    }

    public function test_filtros_y_datos_sembrados_de_autorizaciones(): void
    {
        $this->getJson('/api/autorizaciones-metodo?vigente=1&por_pagina=100')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/autorizaciones-metodo?vigente=0&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');   // vencida y revocada
        $this->getJson('/api/autorizaciones-metodo?buscar=Huella')->assertOk()->assertJsonPath('data.0.trabajador.numero_documento', '70000002');
        $this->getJson('/api/autorizaciones-metodo?metodo_marcacion_id='.$this->metodo('MANUAL').'&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------ Ocurrencia de porteria

    public function test_una_ocurrencia_anulada_no_se_modifica_ni_se_reactiva(): void
    {
        $spec = $this->spec('ocurrencias-porteria');
        $id = $this->crear($spec);

        $this->deleteJson("{$spec['endpoint']}/{$id}")->assertOk()->assertJsonPath('mensaje', 'Ocurrencia anulada.');
        $this->deleteJson("{$spec['endpoint']}/{$id}")->assertOk();   // anular es idempotente
        $this->getJson("{$spec['endpoint']}/{$id}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['OcurrenciaPorteriaEstado' => 'REGISTRADO'])->assertStatus(422)->assertJsonValidationErrors(['OcurrenciaPorteriaEstado']);
        $this->patchJson("{$spec['endpoint']}/{$id}", ['OcurrenciaPorteriaDescripcion' => 'cambio'])->assertStatus(422);
    }

    public function test_una_ocurrencia_nueva_nace_registrada_y_acepta_la_fecha_del_navegador(): void
    {
        $spec = $this->spec('ocurrencias-porteria');

        $this->postJson($spec['endpoint'], $this->payload($spec, ['OcurrenciaPorteriaEstado' => 'ATENDIDO']))
            ->assertStatus(422)->assertJsonValidationErrors(['OcurrenciaPorteriaEstado']);

        // datetime-local del navegador: "2026-09-01T10:30".
        $r = $this->postJson($spec['endpoint'], $this->payload($spec, ['OcurrenciaPorteriaFechaHora' => '2026-09-01T10:30']))->assertCreated();
        $r->assertJsonPath('data.estado', 'REGISTRADO')->assertJsonPath('data.fecha_hora', '2026-09-01 10:30:00')
            ->assertJsonPath('data.trabajador.numero_documento', '70000001')->assertJsonPath('data.eess.codigo', 'EESS-LE-01');

        // Sin fecha: la base pone la actual.
        $sinFecha = $this->payload($spec);
        unset($sinFecha['OcurrenciaPorteriaFechaHora']);
        $this->assertStringStartsWith(now()->format('Y-m-d'), $this->postJson($spec['endpoint'], $sinFecha)->assertCreated()->json('data.fecha_hora'));
    }

    public function test_una_ocurrencia_de_tipo_otro_puede_no_tener_persona_pero_exige_descripcion(): void
    {
        $spec = $this->spec('ocurrencias-porteria');

        $this->postJson($spec['endpoint'], $this->payload($spec, ['OcurrenciaPorteriaTipo' => 'OTRO', 'VinculoLaboralId' => null]))
            ->assertCreated()->assertJsonPath('data.trabajador', null);
        $this->postJson($spec['endpoint'], $this->payload($spec, ['OcurrenciaPorteriaTipo' => 'OTRO', 'VinculoLaboralId' => null, 'OcurrenciaPorteriaDescripcion' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['OcurrenciaPorteriaDescripcion']);
    }

    public function test_filtros_y_datos_sembrados_de_ocurrencias(): void
    {
        $this->getJson('/api/ocurrencias-porteria?por_pagina=100')->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('data.0.fecha_hora', '2026-09-30 08:10:00');
        $this->getJson('/api/ocurrencias-porteria?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false);
        $this->getJson('/api/ocurrencias-porteria?tipo=SALIDA_CON_PAPELETA&por_pagina=100')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ocurrencias-porteria?buscar=Quispe')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.trabajador.nombre_completo', 'Quispe Huamán, María Elena');
        $this->getJson('/api/ocurrencias-porteria?desde=2026-09-29&hasta=2026-09-29')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ocurrencias-porteria?buscar=energía')->assertOk()->assertJsonPath('data.0.trabajador', null)->assertJsonPath('data.0.usuario.nombre', 'vsanchez');
    }
}
