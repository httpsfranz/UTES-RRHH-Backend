<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Inicio de sesion, permisos por rol y alcance por establecimiento / persona (RIT: cada quien opera segun su funcion).
 * La clave de los usuarios sembrados es "Prueba2026*".
 */
class Nivel7AccesoTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private const CLAVE = 'Prueba2026*';

    // ------------------------------------------------------------------ sesion

    public function test_sin_sesion_la_api_responde_401_salvo_el_login(): void
    {
        $this->sinSesion();

        $this->getJson('/api/papeletas')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->postJson('/api/papeletas/1/aprobar', ['UsuarioId' => 1])->assertUnauthorized();
        $this->postJson('/api/auth/login', [])->assertStatus(422);
    }

    public function test_login_correcto_entrega_token_roles_permisos_y_alcance_y_deja_la_sesion_registrada(): void
    {
        $this->sinSesion();

        $respuesta = $this->postJson('/api/auth/login', ['usuario' => 'crojas', 'password' => self::CLAVE])->assertOk();

        $this->assertNotEmpty($respuesta->json('token'));
        $this->assertSame('crojas', $respuesta->json('usuario.nombre'));
        $this->assertContains('RESP_EESS', array_column($respuesta->json('roles'), 'codigo'));
        $this->assertContains('SOLICITUDES_APROBAR', $respuesta->json('permisos'));
        $this->assertNotContains('SEGURIDAD_VER', $respuesta->json('permisos'));
        $this->assertFalse($respuesta->json('alcance.global'));
        $this->assertNotEmpty($respuesta->json('alcance.eess_ids'));
        $this->assertSame(1, DB::table('Seguridad.SesionAcceso')->where('UsuarioId', $this->usuarioId('crojas'))->where('SesionAccesoResultado', 'EXITOSO')->whereNull('SesionAccesoFechaFin')->count());
        $this->assertNotNull($this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$respuesta->json('token')])->json('usuario.id'));
    }

    public function test_login_con_clave_incorrecta_o_usuario_inactivo_no_entrega_token(): void
    {
        $this->sinSesion();

        $this->postJson('/api/auth/login', ['usuario' => 'crojas', 'password' => 'incorrecta'])->assertStatus(401)->assertJsonMissing(['token']);
        $this->postJson('/api/auth/login', ['usuario' => 'no.existe', 'password' => self::CLAVE])->assertStatus(401);
        $this->postJson('/api/auth/login', ['usuario' => 'jretirado', 'password' => self::CLAVE])->assertStatus(401);

        $resultados = DB::table('Seguridad.SesionAcceso')->whereIn('UsuarioId', [$this->usuarioId('crojas'), $this->usuarioId('jretirado')])->pluck('SesionAccesoResultado')->all();
        $this->assertContains('CLAVE_INCORRECTA', $resultados);
        $this->assertContains('USUARIO_INACTIVO', $resultados);
    }

    public function test_el_cierre_de_sesion_invalida_el_token_y_registra_la_hora(): void
    {
        $this->sinSesion();
        $token = $this->postJson('/api/auth/login', ['usuario' => 'pgutierrez', 'password' => self::CLAVE])->json('token');

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertNotNull(DB::table('Seguridad.SesionAcceso')->where('UsuarioId', $this->usuarioId('pgutierrez'))->orderByDesc('SesionAccesoId')->value('SesionAccesoFechaFin'));
    }

    // ------------------------------------------------------------------ permisos por rol

    public function test_el_trabajador_solo_consulta_su_asistencia_y_solicitudes(): void
    {
        $this->actuarComo('mquispe');

        $this->getJson('/api/marcaciones')->assertOk();
        $this->getJson('/api/papeletas')->assertOk();
        $this->postJson('/api/papeletas', [])->assertForbidden();
        $this->getJson('/api/trabajadores')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/auditoria')->assertForbidden();
        $this->getJson('/api/consolidados-asistencia')->assertForbidden();
        $this->getJson('/api/cargas-asistencia-manual')->assertForbidden();   // asistencia, pero no es informacion propia
        $this->getJson('/api/tipos-papeleta')->assertOk();   // catalogo de apoyo
    }

    public function test_el_trabajador_solo_ve_sus_propios_registros(): void
    {
        $this->actuarComo('mquispe');
        $documento = DB::table('Personal.Trabajador as t')->join('Seguridad.Usuario as u', 'u.TrabajadorId', '=', 't.TrabajadorId')->where('u.UsuarioNombre', 'mquispe')->value('t.TrabajadorNumeroDocumento');

        foreach (['marcaciones', 'asistencia-diaria', 'papeletas'] as $recurso) {
            $filas = $this->getJson("/api/{$recurso}?por_pagina=100")->assertOk()->json('data');
            foreach ($filas as $fila) {
                $this->assertSame($documento, $fila['trabajador']['numero_documento'], "{$recurso} muestra datos de otra persona");
            }
        }
        $ajeno = DB::table('Personal.VinculoLaboral')->where('TrabajadorId', '<>', DB::table('Seguridad.Usuario')->where('UsuarioNombre', 'mquispe')->value('TrabajadorId'))->value('VinculoLaboralId');
        $this->getJson('/api/marcaciones?vinculo_laboral_id='.$ajeno)->assertForbidden();
    }

    public function test_el_programador_elabora_pero_no_publica_la_programacion(): void
    {
        $this->actuarComo('lcastillo');
        $periodo = $this->periodoDeProgramacion('BORRADOR', 'EESS-LE-01', '2026-11-02', '2026-11-30');

        $this->getJson('/api/programaciones-periodo')->assertOk();
        $this->postJson("/api/programaciones-periodo/{$periodo}/publicar", [])->assertForbidden();   // aprueba el jefe del establecimiento
        $this->postJson('/api/papeletas/1/aprobar', [])->assertForbidden();
        $this->getJson('/api/consolidados-asistencia')->assertForbidden();
    }

    public function test_el_responsable_del_establecimiento_aprueba_solicitudes_y_el_aprobador_es_el_de_la_sesion(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->actuarComo('rvargas');
        $id = $this->postJson('/api/papeletas', $this->papeleta($vinculo))->assertCreated()->json('data.id');
        $this->actuarComo('crojas');

        // Aunque el cuerpo diga que aprueba otro usuario, queda registrado quien tiene la sesion.
        $this->postJson("/api/papeletas/{$id}/aprobar", ['UsuarioId' => $this->usuarioId('pgutierrez')])->assertOk()
            ->assertJsonPath('data.estado', 'APROBADO')->assertJsonPath('data.usuario_autorizacion.nombre', 'crojas');
        $this->assertSame($this->usuarioId('rvargas'), (int) DB::table('Solicitudes.Papeleta')->where('PapeletaId', $id)->value('UsuarioRegistroId'));
    }

    public function test_solo_el_administrador_gestiona_usuarios_y_ve_la_auditoria(): void
    {
        $this->actuarComo('gdangelo');   // RRHH de la Red
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/auditoria')->assertForbidden();
        $this->getJson('/api/parametros-sistema')->assertOk();   // los puede ver, no cambiarlos
        $this->postJson('/api/parametros-sistema', [])->assertForbidden();

        $this->actuarComo('pgutierrez');
        $this->getJson('/api/usuarios')->assertOk();
        $this->getJson('/api/auditoria')->assertOk();
    }

    // ------------------------------------------------------------------ alcance por establecimiento

    public function test_el_responsable_solo_ve_su_establecimiento(): void
    {
        $this->actuarComo('crojas');
        $propio = DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $this->usuarioId('crojas'))->value('EessId');
        $this->assertNotNull($propio);
        $otro = DB::table('Organizacion.EstablecimientoSalud')->where('EessId', '<>', $propio)->value('EessId');

        $this->getJson('/api/marcaciones?eess_id='.$otro)->assertForbidden();
        $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $otro, 'anio' => 2026, 'mes' => 9]))->assertForbidden();
        $this->getJson('/api/asistencia-diaria/calendario?'.http_build_query(['eess_id' => $propio, 'anio' => 2026, 'mes' => 9]))->assertOk();
        foreach ($this->getJson('/api/vinculos-laborales?por_pagina=100')->assertOk()->json('data') as $vinculo) {
            $this->assertEquals($propio, $vinculo['eess_id'], 'Un listado sin eess_id debe limitarse al establecimiento del usuario.');
        }
    }

    public function test_el_jefe_de_microred_ve_los_establecimientos_de_su_microred(): void
    {
        $this->sinSesion();
        $acceso = $this->postJson('/api/auth/login', ['usuario' => 'jefe.microred', 'password' => self::CLAVE])->assertOk();

        $propios = DB::table('Organizacion.EstablecimientoSalud')->whereIn('MicroredId', DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $this->usuarioId('jefe.microred'))->whereNotNull('MicroredId')->pluck('MicroredId'))->pluck('EessId')->all();
        $propios = array_merge($propios, DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $this->usuarioId('jefe.microred'))->whereNotNull('EessId')->pluck('EessId')->all());
        $this->assertEqualsCanonicalizing(array_values(array_unique($propios)), $acceso->json('alcance.eess_ids'));
        $this->assertFalse($acceso->json('alcance.global'));
    }

    // ------------------------------------------------------------------ auditoria y resumen

    public function test_lo_que_se_crea_o_modifica_queda_en_la_auditoria_con_el_usuario(): void
    {
        $this->actuarComo('crojas');
        $antes = DB::table('Seguridad.Auditoria')->count();

        $id = $this->postJson('/api/papeletas', $this->papeleta($this->nuevoVinculo()))->assertCreated()->json('data.id');

        $fila = DB::table('Seguridad.Auditoria')->where('AuditoriaTabla', 'Papeleta')->where('AuditoriaRegistroId', (string) $id)->first();
        $this->assertNotNull($fila);
        $this->assertSame('INSERT', $fila->AuditoriaOperacion);
        $this->assertSame($this->usuarioId('crojas'), (int) $fila->UsuarioId);
        $this->assertGreaterThan($antes, DB::table('Seguridad.Auditoria')->count());
    }

    private function kpi(array $panel, string $etiqueta): int|float
    {
        foreach ($panel['widgets'] as $w) {
            foreach ($w['tipo'] === 'kpis' ? $w['items'] : [] as $item) {
                if ($item['etiqueta'] === $etiqueta) {
                    return $item['valor'];
                }
            }
        }
        $this->fail("Sin indicador {$etiqueta}");
    }

    public function test_el_panel_de_inicio_depende_del_rol_y_se_limita_al_alcance(): void
    {
        $red = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->actuarComo('crojas');
        $eess = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->actuarComo('mquispe');
        $propio = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->actuarComo('programador');
        $prog = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame(['red', 'Toda la Red'], [$red['perfil'], $red['alcance']]);
        $this->assertSame('eess', $eess['perfil']);
        $this->assertSame('personal', $propio['perfil']);
        $this->assertSame('programacion', $prog['perfil']);
        $this->assertLessThanOrEqual($this->kpi($red, 'Trabajadores activos'), $this->kpi($eess, 'Trabajadores activos'));
        $this->assertContains('accesos', array_column($red['widgets'], 'id'), 'el administrador ve la seguridad');
        $this->assertNotContains('accesos', array_column($eess['widgets'], 'id'));
        $this->assertNotContains('por_eess', array_column($eess['widgets'], 'id'), 'un solo establecimiento no se compara con otros');
        $this->assertNotContains('pendientes', array_column($propio['widgets'], 'id'), 'el trabajador no resuelve solicitudes');
        $this->assertContains('cobertura', array_column($prog['widgets'], 'id'));
    }

    public function test_el_filtro_del_panel_no_amplia_el_alcance(): void
    {
        $this->actuarComo('crojas');
        $propios = $this->getJson('/api/dashboard')->assertOk()->json();
        $ajeno = DB::table('Organizacion.EstablecimientoSalud')->where('EessId', '<>', $propios['filtros']['establecimientos'][0]['id'] ?? 0)->value('EessId');
        $r = $this->getJson('/api/dashboard?eess_id='.$ajeno)->assertOk()->json();

        $this->assertSame(0, $this->kpi($r, 'Trabajadores activos'));
        $this->getJson('/api/dashboard?perfil=cualquiera')->assertStatus(422);
    }

    public function test_los_listados_de_seguridad_traen_sus_relaciones(): void
    {
        $usuario = collect($this->getJson('/api/usuarios?buscar=resp.eess')->assertOk()->json('data'))->firstWhere('nombre', 'resp.eess');
        $this->assertSame(['RESP_EESS'], array_column($usuario['roles'], 'codigo'));
        $this->assertSame('EESS', $usuario['ambitos'][0]['alcance']);

        $rol = collect($this->getJson('/api/roles?por_pagina=100')->json('data'))->firstWhere('codigo', 'RESP_EESS');
        $this->assertGreaterThan(0, $rol['permisos_total']);
        $this->assertGreaterThan(0, $rol['usuarios_total']);

        $trabajador = $this->getJson('/api/trabajadores?buscar=99000003')->assertOk()->json('data.0');
        $this->assertArrayHasKey('vinculos', $trabajador);
    }

    public function test_las_notificaciones_solo_se_ven_las_propias(): void
    {
        $this->actuarComo('mquispe');
        $yo = $this->usuarioId('mquispe');

        foreach ($this->getJson('/api/notificaciones?por_pagina=100')->assertOk()->json('data') as $fila) {
            $this->assertSame($yo, $fila['usuario_id']);
        }
    }

    // ------------------------------------------------------------------ usuarios predeterminados

    public function test_los_usuarios_predeterminados_existen_con_su_rol_y_ambito(): void
    {
        foreach (['admin' => 'ADMIN', 'rrhh' => 'RRHH_RED', 'jefe.microred' => 'JEFE_MICRORED', 'resp.eess' => 'RESP_EESS', 'programador' => 'PROGRAMADOR', 'trabajador' => 'TRABAJADOR', 'porteria' => 'PORTERIA'] as $usuario => $rol) {
            $this->sinSesion();
            $r = $this->postJson('/api/auth/login', ['usuario' => $usuario, 'password' => self::CLAVE])->assertOk();
            $this->assertSame([$rol], array_column($r->json('roles'), 'codigo'), $usuario);
        }
        $this->sinSesion();
        $admin = $this->postJson('/api/auth/login', ['usuario' => 'admin', 'password' => self::CLAVE])->json();
        $this->assertTrue($admin['alcance']['global']);
        $this->assertContains('SEGURIDAD_AUDITORIA', $admin['permisos']);
    }

    /** @return array<string,mixed> */
    private function papeleta(int $vinculo): array
    {
        $tipo = DB::table('Solicitudes.TipoPapeleta as t')->whereExists(fn ($q) => $q->selectRaw('1')->from('Solicitudes.MotivoPapeleta as m')->whereColumn('m.TipoPapeletaId', 't.TipoPapeletaId'))->where('t.TipoPapeletaRequiereSustento', 0)->value('t.TipoPapeletaId');

        return [
            'VinculoLaboralId' => $vinculo, 'TipoPapeletaId' => $tipo,
            'MotivoPapeletaId' => DB::table('Solicitudes.MotivoPapeleta')->where('TipoPapeletaId', $tipo)->value('MotivoPapeletaId'),
            'PapeletaFecha' => '2026-09-21', 'PapeletaHoraSalida' => '10:00', 'PapeletaHoraRetorno' => '11:00', 'PapeletaMotivo' => 'Trámite',
        ];
    }
}
