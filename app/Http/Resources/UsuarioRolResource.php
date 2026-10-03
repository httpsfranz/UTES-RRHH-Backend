<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioRolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->UsuarioRolId,
            'usuario_id' => $this->UsuarioId,
            'rol_id' => $this->RolId,
            'fecha_inicio' => optional($this->UsuarioRolFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->UsuarioRolFechaFin)->format('Y-m-d'),
            'activo' => (bool) $this->UsuarioRolEstado,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
            'rol' => $this->whenLoaded('rol', fn () => $this->rol ? [
                'id' => $this->rol->RolId,
                'codigo' => $this->rol->RolCodigo,
                'nombre' => $this->rol->RolNombre,
            ] : null),
        ];
    }
}
