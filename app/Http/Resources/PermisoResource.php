<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Seguridad\Permiso
 */
class PermisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->PermisoId,
            'codigo'      => $this->PermisoCodigo,
            'nombre'      => $this->PermisoNombre,
            'modulo'      => $this->PermisoModulo,
            'descripcion' => $this->PermisoDescripcion,
            'activo'      => (bool) $this->PermisoEstado,
        ];
    }
}
