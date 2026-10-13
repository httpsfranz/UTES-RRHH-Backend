<?php

namespace App\Http\Resources;

use App\Models\Seguridad\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rol
 */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->RolId,
            'codigo' => $this->RolCodigo,
            'nombre' => $this->RolNombre,
            'descripcion' => $this->RolDescripcion,
            'activo' => (bool) $this->RolEstado,
            'permisos_total' => $this->when(array_key_exists('permisos_total', $this->getAttributes()), fn () => (int) $this->permisos_total),
            'usuarios_total' => $this->when(array_key_exists('usuarios_total', $this->getAttributes()), fn () => (int) $this->usuarios_total),
        ];
    }
}
