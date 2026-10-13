<?php

namespace App\Support;

use App\Models\Seguridad\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Que establecimientos puede ver quien consulta (ver AccesoUsuario). Un administrador o un usuario con ambito global ve
 * todos; los demas, solo los establecimientos de su ambito (su EESS, o los de su microred).
 *
 * Los listados que aceptan ?eess_id= usan restringe()/ids(): si el usuario esta limitado, el filtro por establecimiento
 * se aplica SIEMPRE (con el eess_id pedido si esta dentro de su ambito; si no, con todos los suyos) y pedir otro
 * establecimiento es un 403. Quien no tiene sesion (comandos, seeders) no se restringe.
 */
class AlcanceDeEess
{
    /** @return list<int>|null ids de los EESS permitidos; null = todos */
    public static function permitidos(?Authenticatable $usuario = null): ?array
    {
        if (! $usuario instanceof Usuario) {
            return null;
        }

        return AccesoUsuario::de($usuario)->eessIds;
    }

    /** @throws AuthorizationException si el usuario no puede ver ese establecimiento */
    public static function exigir(int $eessId, ?Authenticatable $usuario = null): void
    {
        $permitidos = self::permitidos($usuario);
        if ($permitidos !== null && ! in_array($eessId, $permitidos, true)) {
            throw new AuthorizationException('No puedes ver la información de otro establecimiento de salud.');
        }
    }

    /** true si el listado debe filtrarse por establecimiento (lo pidio la peticion o lo impone el ambito del usuario). */
    public static function restringe(Request $request): bool
    {
        return $request->filled('eess_id') || self::permitidos($request->user()) !== null;
    }

    /**
     * Establecimientos por los que filtrar. Con ?eess_id= es ese (y debe estar en el ambito); sin el, los del usuario.
     *
     * @return list<int>
     */
    public static function ids(Request $request): array
    {
        if ($request->filled('eess_id')) {
            self::exigir($request->integer('eess_id'), $request->user());

            return [$request->integer('eess_id')];
        }

        return self::permitidos($request->user()) ?? [];
    }

    /** true si el listado debe filtrarse por vinculo laboral (lo pidio la peticion o el usuario solo ve lo propio). */
    public static function restringeVinculos(Request $request): bool
    {
        return $request->filled('vinculo_laboral_id') || self::vinculosPropios($request->user()) !== null;
    }

    /**
     * Vinculos por los que filtrar. Con ?vinculo_laboral_id= es ese (y, si el usuario solo ve lo propio, debe ser suyo).
     *
     * @return list<int>
     */
    public static function vinculos(Request $request): array
    {
        $propios = self::vinculosPropios($request->user());
        if ($request->filled('vinculo_laboral_id')) {
            $id = $request->integer('vinculo_laboral_id');
            if ($propios !== null && ! in_array($id, $propios, true)) {
                throw new AuthorizationException('Solo puedes consultar tu propia información.');
            }

            return [$id];
        }

        return $propios ?? [];
    }

    /**
     * Vinculos laborales propios cuando el usuario solo es TRABAJADOR; null = sin restriccion por persona.
     *
     * @return list<int>|null
     */
    public static function vinculosPropios(?Authenticatable $usuario): ?array
    {
        if (! $usuario instanceof Usuario) {
            return null;
        }
        $acceso = AccesoUsuario::de($usuario);

        return $acceso->propio ? $acceso->vinculoIds : null;
    }
}
