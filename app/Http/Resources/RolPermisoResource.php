<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RolPermisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->RolPermisoId,
            'rol_id' => $this->RolId,
            'permiso_id' => $this->PermisoId,
            'activo' => (bool) $this->RolPermisoEstado,
            'rol' => $this->whenLoaded('rol', fn () => [
                'id' => $this->rol->RolId,
                'codigo' => $this->rol->RolCodigo,
                'nombre' => $this->rol->RolNombre,
            ]),
            'permiso' => $this->whenLoaded('permiso', fn () => [
                'id' => $this->permiso->PermisoId,
                'codigo' => $this->permiso->PermisoCodigo,
                'nombre' => $this->permiso->PermisoNombre,
                'modulo' => $this->permiso->PermisoModulo,
            ]),
        ];
    }
}
