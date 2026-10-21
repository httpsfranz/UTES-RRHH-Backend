<?php

namespace Tests\Feature;

use App\Models\Seguridad\Usuario;
use App\Services\NotificacionService;
use App\Support\HoraLocal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ArmaEscenarios;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-19): seguridad de cuentas y sesiones, roles, auditoria inmutable, bandeja de
 * notificaciones, revision del consolidado, numeros internos automaticos, record vacacional sin año digitado,
 * supervision que da inicio al PAD y calendario no laborable.
 */
class Nivel9SeguridadYFlujosTest extends TestCase
{
    use ArmaEscenarios;
    use DatabaseTransactions;

    private function usuarioDePrueba(string $clave = 'Clave2026x'): Usuario
    {
        $usuario = Usuario::query()->where('UsuarioNombre', 'mquispe')->firstOrFail();
        $usuario->update(['UsuarioPasswordHash' => Hash::make($clave), 'UsuarioBloqueado' => false, 'UsuarioIntentosFallidos' => 0]);

        return $usuario;
    }

    // ------------------------------------------------------------------ cuentas y sesiones

    public function test_los_intentos_fallidos_bloquean_la_cuenta_y_queda_auditado(): void
    {
        $usuario = $this->usuarioDePrueba();
        $this->sinSesion();

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/login', ['usuario' => 'mquispe', 'password' => 'mala'])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['usuario' => 'mquispe', 'password' => 'mala'])->assertStatus(423)->assertJsonPath('bloqueada', true);
        // Bloqueada: ni con la clave correcta entra.
        $this->postJson('/api/auth/login', ['usuario' => 'mquispe', 'password' => 'Clave2026x'])->assertStatus(423);

        $this->assertTrue((bool) $usuario->fresh()->UsuarioBloqueado);
        $this->assertNotNull($usuario->fresh()->UsuarioBloqueadoHasta);
        $this->assertTrue(DB::table('Seguridad.Auditoria')->where('UsuarioId', $usuario->UsuarioId)->where('AuditoriaOperacion', 'BLOQUEO')->exists());
        $this->assertSame(5, DB::table('Seguridad.Auditoria')->where('UsuarioId', $usuario->UsuarioId)->where('AuditoriaOperacion', 'LOGIN_FALLIDO')->where('AuditoriaResultado', 'FALLO')->count());
    }

    public function test_bloquear_una_cuenta_revoca_sus_sesiones_y_la_sesion_revocada_deja_de_servir(): void
    {
        $this->usuarioDePrueba();
        $this->sinSesion();
        $token = $this->postJson('/api/auth/login', ['usuario' => 'mquispe', 'password' => 'Clave2026x'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->actuarComo('pgutierrez');
        $id = Usuario::query()->where('UsuarioNombre', 'mquispe')->value('UsuarioId');
        $this->postJson("/api/usuarios/{$id}/bloquear", ['Motivo' => 'Uso indebido de la cuenta'])->assertOk()->assertJsonPath('data.estado_cuenta', 'BLOQUEADO');

        $sesion = DB::table('Seguridad.SesionAcceso')->where('UsuarioId', $id)->where('SesionAccesoResultado', 'EXITOSO')->orderByDesc('SesionAccesoId')->first();
        $this->assertSame('BLOQUEO', $sesion->SesionAccesoMotivoCierre);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_una_sesion_inactiva_expira(): void
    {
        $this->usuarioDePrueba();
        $this->sinSesion();
        $token = $this->postJson('/api/auth/login', ['usuario' => 'mquispe', 'password' => 'Clave2026x'])->assertOk()->json('token');
        DB::table('Seguridad.SesionAcceso')->where('UsuarioId', Usuario::query()->where('UsuarioNombre', 'mquispe')->value('UsuarioId'))
            ->whereNull('SesionAccesoFechaFin')->update(['SesionAccesoUltimaActividad' => HoraLocal::ahora()->subHours(3)->toDateTimeString()]);

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('motivo', 'INACTIVIDAD');
    }

    public function test_el_usuario_nuevo_debe_cambiar_su_clave_y_puede_hacerlo(): void
    {
        $trabajador = $this->nuevoTrabajador();
        $this->postJson('/api/usuarios', ['TrabajadorId' => $trabajador, 'UsuarioNombre' => 'nuevo.usuario', 'UsuarioPassword' => 'Inicial2026', 'UsuarioPasswordConfirmacion' => 'Inicial2026', 'UsuarioEstadoCuenta' => 'ACTIVO'])
            ->assertCreated()->assertJsonPath('data.debe_cambiar_clave', true)->assertJsonPath('data.estado_cuenta', 'ACTIVO');

        $this->actuarComo('nuevo.usuario');
        $this->postJson('/api/auth/cambiar-password', ['PasswordActual' => 'Inicial2026', 'PasswordNueva' => 'Propia2026', 'PasswordConfirmacion' => 'Propia2026'])
            ->assertOk()->assertJsonPath('usuario.debe_cambiar_clave', false);
    }

    // ------------------------------------------------------------------ roles y auditoria

    public function test_un_rol_activo_no_se_elimina_y_la_asignacion_registra_quien_la_hizo(): void
    {
        $rol = $this->postJson('/api/roles', ['RolNombre' => 'Auditor interno', 'RolDescripcion' => 'Revisa la auditoria'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/roles/{$rol}")->assertStatus(422);

        $usuario = Usuario::query()->where('UsuarioNombre', 'mquispe')->value('UsuarioId');
        $asignacion = $this->postJson('/api/usuarios-roles', ['UsuarioId' => $usuario, 'RolId' => $rol])->assertCreated()->assertJsonPath('data.asignado_por', 'pgutierrez')->json('data.id');
        $this->deleteJson("/api/usuarios-roles/{$asignacion}")->assertOk();
        $this->getJson("/api/usuarios-roles/{$asignacion}")->assertJsonPath('data.revocado_por', 'pgutierrez');
    }

    public function test_la_auditoria_no_se_puede_modificar_y_se_filtra_y_exporta(): void
    {
        $this->getJson('/api/permisos')->assertOk();
        $this->actuarComo('mquispe');
        $this->getJson('/api/usuarios')->assertForbidden();   // queda como ACCESO_DENEGADO
        $this->actuarComo('pgutierrez');

        $this->getJson('/api/auditoria?operacion=ACCESO_DENEGADO&resultado=DENEGADO')->assertOk()->assertJsonPath('data.0.resultado', 'DENEGADO');
        $this->get('/api/auditoria/exportar?operacion=ACCESO_DENEGADO')->assertOk()->assertHeader('content-disposition');

        $id = DB::table('Seguridad.Auditoria')->value('AuditoriaId');
        $this->expectException(QueryException::class);
        DB::table('Seguridad.Auditoria')->where('AuditoriaId', $id)->update(['AuditoriaDetalle' => 'alterado']);
    }

    // ------------------------------------------------------------------ notificaciones

    public function test_la_bandeja_cuenta_las_no_leidas_y_se_marcan_como_leidas(): void
    {
        $yo = Usuario::query()->where('UsuarioNombre', 'pgutierrez')->value('UsuarioId');
        app(NotificacionService::class)->notificar($yo, 'PAPELETA_RECHAZADA', 'Papeleta rechazada', 'Prueba de bandeja');

        $bandeja = $this->getJson('/api/notificaciones/bandeja')->assertOk()->json();
        $this->assertGreaterThan(0, $bandeja['no_leidas']);
        $this->assertSame('PENDIENTE', collect($bandeja['data'])->firstWhere('titulo', 'Papeleta rechazada')['correo_estado'] ?? 'PENDIENTE');

        $this->postJson('/api/notificaciones/leer-todas')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notificaciones/bandeja')->json('no_leidas'));
    }

    public function test_el_correo_se_envia_y_queda_registrada_la_entrega(): void
    {
        $usuario = Usuario::query()->where('UsuarioNombre', 'mquispe')->firstOrFail();
        $servicio = app(NotificacionService::class);
        $n = $servicio->notificar($usuario->UsuarioId, 'PAPELETA_RECHAZADA', 'Papeleta rechazada', 'Correo de prueba');
        $servicio->enviarCorreo($n->NotificacionId);

        $this->assertSame('ENVIADO', $n->fresh()->NotificacionCorreoEstado);
        $this->assertSame(1, $n->fresh()->NotificacionCorreoIntentos);
    }

    // ------------------------------------------------------------------ consolidado

    public function test_el_consolidado_se_observa_se_aprueba_y_se_exporta(): void
    {
        $id = $this->consolidado($this->nuevoVinculo());

        $this->postJson("/api/consolidados-asistencia/{$id}/observar", ['Observacion' => 'Revisar la falta del 14'])->assertOk()
            ->assertJsonPath('data.estado', 'OBSERVADO')->assertJsonPath('data.revisado_por', 'pgutierrez');
        $this->postJson("/api/consolidados-asistencia/{$id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'CONFORME');
        $this->getJson("/api/consolidados-asistencia/{$id}/incidencias")->assertOk()->assertJsonStructure(['data']);

        $this->get('/api/consolidados-asistencia/exportar?periodo_asistencia_id='.$this->periodoDeAsistenciaId(10))->assertOk()->assertDownload();
    }

    public function test_un_periodo_cerrado_no_se_reabre_y_registra_quien_lo_cerro(): void
    {
        $periodo = $this->postJson('/api/periodos-asistencia', ['PeriodoAsistenciaAnio' => 2031, 'PeriodoAsistenciaMes' => 3, 'PeriodoAsistenciaFechaInicio' => '2031-03-01', 'PeriodoAsistenciaFechaFin' => '2031-03-31'])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/periodos-asistencia/{$periodo}", ['PeriodoAsistenciaEstado' => 'CERRADO'])->assertOk()->assertJsonPath('data.cerrado_por', 'pgutierrez');
        $this->patchJson("/api/periodos-asistencia/{$periodo}", ['PeriodoAsistenciaEstado' => 'ABIERTO'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ numeros internos y record vacacional

    public function test_los_numeros_internos_se_generan_solos(): void
    {
        $anio = now('America/Lima')->year;
        $tipoFalta = DB::table('Disciplina.TipoFaltaDisciplinaria')->value('TipoFaltaDisciplinariaId');

        $numero = $this->postJson('/api/expedientes-pad', ['VinculoLaboralId' => $this->nuevoVinculo(), 'TipoFaltaDisciplinariaId' => $tipoFalta, 'ExpedientePadFechaInicio' => '2026-10-01'])
            ->assertCreated()->json('data.numero');

        $this->assertMatchesRegularExpression("/^PAD-{$anio}-\\d{4}$/", $numero);
    }

    public function test_el_año_del_record_vacacional_no_se_digita(): void
    {
        $this->postJson('/api/periodos-vacacionales', ['VinculoLaboralId' => $this->nuevoVinculo(['VinculoLaboralFechaInicio' => '2025-03-01']), 'PeriodoVacacionalFechaInicio' => '2025-03-01'])
            ->assertCreated()->assertJsonPath('data.fecha_fin', '2026-02-28')->assertJsonPath('data.anio', 2026);
    }

    // ------------------------------------------------------------------ supervision -> PAD

    public function test_una_supervision_observada_da_inicio_al_expediente_pad_con_el_acta(): void
    {
        $v = $this->nuevoVinculo();
        $id = $this->postJson('/api/supervisiones-inopinadas', ['EessId' => $this->eessId(), 'VinculoLaboralId' => $v, 'SupervisionInopinadaResultado' => 'Ausente en su puesto', 'SupervisionInopinadaObservacion' => 'No se encontraba en su servicio a las 10:00'])->assertCreated()->json('data.id');
        $this->patchJson("/api/supervisiones-inopinadas/{$id}", ['SupervisionInopinadaEstado' => 'OBSERVADO'])->assertOk();

        $this->postJson("/api/supervisiones-inopinadas/{$id}/iniciar-pad", ['TipoFaltaDisciplinariaId' => DB::table('Disciplina.TipoFaltaDisciplinaria')->value('TipoFaltaDisciplinariaId')])
            ->assertCreated()->assertJsonStructure(['numero']);
        $this->getJson("/api/supervisiones-inopinadas/{$id}")->assertOk()->assertJsonPath('data.expediente_pad.estado', 'INICIADO');
        $this->postJson("/api/supervisiones-inopinadas/{$id}/iniciar-pad", ['TipoFaltaDisciplinariaId' => DB::table('Disciplina.TipoFaltaDisciplinaria')->value('TipoFaltaDisciplinariaId')])->assertStatus(422);
    }

    // ------------------------------------------------------------------ calendario no laborable

    public function test_el_dia_no_laborable_completa_la_norma_y_no_se_duplica_con_la_red(): void
    {
        $this->postJson('/api/calendario-no-laborable', ['CalendarioNoLaborableFecha' => '2031-12-08', 'CalendarioNoLaborableTipo' => 'FERIADO', 'CalendarioNoLaborableDescripcion' => 'Inmaculada Concepción'])
            ->assertCreated()->assertJsonPath('data.activo', true)->assertJsonPath('data.norma_sustento', fn ($n) => str_contains((string) $n, '713'));

        $microred = DB::table('Organizacion.Microred')->value('MicroredId');
        $this->postJson('/api/calendario-no-laborable', ['MicroredId' => $microred, 'CalendarioNoLaborableFecha' => '2031-12-08', 'CalendarioNoLaborableTipo' => 'ASUETO'])
            ->assertUnprocessable()->assertJsonValidationErrors('CalendarioNoLaborableFecha');

        $this->getJson('/api/calendario-no-laborable?anio=2031&mes=12')->assertOk()->assertJsonPath('data.0.fecha', '2031-12-08');
    }
}
