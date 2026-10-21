<?php

namespace Tests\Feature;

use App\Models\Seguridad\Usuario;
use App\Support\ResguardoDeAdministracion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Revision del tester (2026-10-20): el administrador no puede quitarse su propio acceso (desactivarse, bloquearse,
 * retirarse el rol o sus permisos) y el sistema nunca se queda sin un usuario que administre la seguridad.
 */
class Nivel10ResguardoDeAdministracionTest extends TestCase
{
    use DatabaseTransactions;

    private function yo(): Usuario
    {
        return Usuario::query()->where('UsuarioNombre', 'pgutierrez')->firstOrFail();
    }

    /** La asignacion vigente del rol que le da SEGURIDAD_EDITAR al usuario. */
    private function asignacionAdministradora(Usuario $usuario): object
    {
        return DB::table('Seguridad.UsuarioRol as ur')
            ->join('Seguridad.RolPermiso as rp', 'rp.RolId', '=', 'ur.RolId')
            ->join('Seguridad.Permiso as p', 'p.PermisoId', '=', 'rp.PermisoId')
            ->where('ur.UsuarioId', $usuario->UsuarioId)->where('ur.UsuarioRolEstado', 1)->where('p.PermisoCodigo', ResguardoDeAdministracion::PERMISO)
            ->first(['ur.UsuarioRolId', 'ur.RolId']);
    }

    public function test_no_puede_desactivar_ni_bloquear_su_propia_cuenta(): void
    {
        $yo = $this->yo();

        $this->deleteJson("/api/usuarios/{$yo->UsuarioId}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'propia cuenta'));
        $this->patchJson("/api/usuarios/{$yo->UsuarioId}", ['UsuarioEstadoCuenta' => 'INACTIVO'])->assertStatus(422);
        $this->patchJson("/api/usuarios/{$yo->UsuarioId}", ['UsuarioEstadoCuenta' => 'BLOQUEADO', 'UsuarioMotivoBloqueo' => 'Prueba de bloqueo'])->assertStatus(422);

        $yo->refresh();
        $this->assertTrue((bool) $yo->UsuarioEstado);
        $this->assertFalse((bool) $yo->UsuarioBloqueado);
    }

    public function test_no_puede_retirarse_su_rol_ni_desactivarlo_ni_quitarle_el_permiso(): void
    {
        $asignacion = $this->asignacionAdministradora($this->yo());

        $this->deleteJson("/api/usuarios-roles/{$asignacion->UsuarioRolId}")->assertStatus(422);
        $this->patchJson("/api/roles/{$asignacion->RolId}", ['RolEstado' => false])->assertStatus(422);
        $permiso = DB::table('Seguridad.Permiso')->where('PermisoCodigo', ResguardoDeAdministracion::PERMISO)->value('PermisoId');
        $this->deleteJson("/api/permisos/{$permiso}")->assertStatus(422);

        // Nada se aplico a medias: la transaccion se deshizo.
        $this->assertSame(1, (int) DB::table('Seguridad.UsuarioRol')->where('UsuarioRolId', $asignacion->UsuarioRolId)->value('UsuarioRolEstado'));
        $this->assertSame(1, (int) DB::table('Seguridad.Rol')->where('RolId', $asignacion->RolId)->value('RolEstado'));
        $this->assertSame(1, (int) DB::table('Seguridad.Permiso')->where('PermisoId', $permiso)->value('PermisoEstado'));
        $this->assertTrue(ResguardoDeAdministracion::administra($this->yo()));
    }

    public function test_si_puede_desactivar_a_otro_administrador_mientras_quede_uno(): void
    {
        $otro = Usuario::query()->where('UsuarioNombre', 'admin')->firstOrFail();
        $antes = ResguardoDeAdministracion::administradores();

        $this->deleteJson("/api/usuarios/{$otro->UsuarioId}")->assertOk();

        $this->assertFalse((bool) $otro->fresh()->UsuarioEstado);
        $this->assertSame($antes - 1, ResguardoDeAdministracion::administradores());
    }

    public function test_el_ultimo_administrador_activo_no_se_puede_retirar(): void
    {
        // Solo queda pgutierrez: los demas administradores se desactivan directo en la base.
        $yo = $this->yo();
        DB::table('Seguridad.Usuario')->where('UsuarioId', '<>', $yo->UsuarioId)->update(['UsuarioEstado' => 0]);
        $this->assertSame(1, ResguardoDeAdministracion::administradores());

        $this->expectException(HttpException::class);
        ResguardoDeAdministracion::proteger(null, fn () => DB::table('Seguridad.Usuario')->where('UsuarioId', $yo->UsuarioId)->update(['UsuarioEstado' => 0]));
    }
}
