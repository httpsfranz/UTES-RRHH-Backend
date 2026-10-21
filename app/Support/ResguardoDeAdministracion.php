<?php

namespace App\Support;

use App\Models\Seguridad\Usuario;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Evita que la administracion de la seguridad deje al sistema sin quien la administre.
 *
 * Todo cambio de usuarios, roles o permisos se hace dentro de proteger(): se aplica en una transaccion y, si al terminar
 *   - quien lo hizo perdio su propio acceso (se desactivo, se bloqueo, se quito el rol o el permiso), o
 *   - no queda ningun usuario activo y sin bloqueo con SEGURIDAD_EDITAR,
 * se deshace y se responde 422. No importa por que pantalla llegue el cambio: lo que se revisa es el resultado.
 */
class ResguardoDeAdministracion
{
    public const PERMISO = 'SEGURIDAD_EDITAR';

    /**
     * @template T
     *
     * @param  Closure(): T  $cambio
     * @return T
     */
    public static function proteger(?Usuario $actor, Closure $cambio): mixed
    {
        $administraba = $actor !== null && self::administra($actor);

        return DB::transaction(function () use ($actor, $cambio, $administraba) {
            $resultado = $cambio();
            abort_if($administraba && ! self::administra($actor), 422,
                'No puedes quitarte tu propio acceso de administración (desactivar o bloquear tu cuenta, o retirar tu rol o sus permisos): pídeselo a otro administrador.');
            abort_if(self::administradores() === 0, 422,
                'El sistema debe conservar al menos un usuario activo que administre la seguridad: asigna ese rol a otro usuario antes de hacer este cambio.');

            return $resultado;
        });
    }

    /** ¿El usuario, tal como esta ahora en la base, puede administrar la seguridad? */
    public static function administra(Usuario $usuario): bool
    {
        $actual = Usuario::query()->find($usuario->UsuarioId);   // instancia nueva: sin el acceso calculado en cache

        return $actual !== null && $actual->UsuarioEstado && ! $actual->UsuarioBloqueado && AccesoUsuario::de($actual)->tiene(self::PERMISO);
    }

    /** Usuarios activos, sin bloqueo, con un rol vigente que da SEGURIDAD_EDITAR. */
    public static function administradores(): int
    {
        $hoy = HoraLocal::hoy()->toDateString();

        return DB::table('Seguridad.Usuario as u')
            ->join('Seguridad.UsuarioRol as ur', 'ur.UsuarioId', '=', 'u.UsuarioId')
            ->join('Seguridad.Rol as r', 'r.RolId', '=', 'ur.RolId')
            ->join('Seguridad.RolPermiso as rp', 'rp.RolId', '=', 'r.RolId')
            ->join('Seguridad.Permiso as p', 'p.PermisoId', '=', 'rp.PermisoId')
            ->where('u.UsuarioEstado', 1)->where('u.UsuarioBloqueado', 0)
            ->where('ur.UsuarioRolEstado', 1)->where('r.RolEstado', 1)->where('rp.RolPermisoEstado', 1)->where('p.PermisoEstado', 1)
            ->where('p.PermisoCodigo', self::PERMISO)
            ->whereDate('ur.UsuarioRolFechaInicio', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('ur.UsuarioRolFechaFin')->orWhereDate('ur.UsuarioRolFechaFin', '>=', $hoy))
            ->distinct()->count('u.UsuarioId');
    }
}
