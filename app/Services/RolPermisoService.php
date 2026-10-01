<?php

namespace App\Services;

use App\Models\Seguridad\Rol;
use App\Models\Seguridad\RolPermiso;
use Illuminate\Support\Facades\DB;

/**
 * Asignacion de permisos a un rol. Toca varias filas de Seguridad.RolPermiso, asi que va en una
 * transaccion: o queda el conjunto completo que se pidio, o no cambia nada.
 */
class RolPermisoService
{
    /**
     * Deja al rol con EXACTAMENTE estos permisos activos.
     *   - los que ya no estan en la lista se eliminan (tabla puente: borrar la fila es lo correcto);
     *   - los nuevos se insertan;
     *   - los que existian pero estaban inactivos se reactivan.
     *
     * @param  list<int>  $permisoIds
     * @return array{asignados: int, quitados: int}
     */
    public function sincronizar(Rol $rol, array $permisoIds): array
    {
        $permisoIds = array_values(array_unique(array_map('intval', $permisoIds)));

        return DB::transaction(function () use ($rol, $permisoIds) {
            $actuales = RolPermiso::query()->where('RolId', $rol->RolId)->pluck('RolPermisoEstado', 'PermisoId');

            $sobran = $actuales->keys()->map(fn ($id) => (int) $id)->diff($permisoIds)->values()->all();
            if ($sobran !== []) {
                RolPermiso::query()->where('RolId', $rol->RolId)->whereIn('PermisoId', $sobran)->delete();
            }

            foreach ($permisoIds as $permisoId) {
                if (! $actuales->has($permisoId)) {
                    RolPermiso::create(['RolId' => $rol->RolId, 'PermisoId' => $permisoId, 'RolPermisoEstado' => true]);
                } elseif (! $actuales->get($permisoId)) {
                    RolPermiso::query()->where(['RolId' => $rol->RolId, 'PermisoId' => $permisoId])->update(['RolPermisoEstado' => true]);
                }
            }

            return ['asignados' => count($permisoIds), 'quitados' => count($sobran)];
        });
    }
}
