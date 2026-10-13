<?php

namespace App\Http\Resources;

use App\Models\Seguridad\Permiso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permiso
 */
class PermisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->PermisoId,
            'codigo' => $this->PermisoCodigo,
            'nombre' => $this->PermisoNombre,
            'modulo' => $this->PermisoModulo,
            'descripcion' => $this->PermisoDescripcion,
            'activo' => (bool) $this->PermisoEstado,
            'roles_total' => $this->when(array_key_exists('roles_total', $this->getAttributes()), fn () => (int) $this->roles_total),
        ];
    }
}
