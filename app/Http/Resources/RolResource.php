<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Seguridad\Rol
 */
class RolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->RolId,
            'codigo'      => $this->RolCodigo,
            'nombre'      => $this->RolNombre,
            'descripcion' => $this->RolDescripcion,
            'activo'      => (bool) $this->RolEstado,
        ];
    }
}
