<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Nunca expone UsuarioPasswordHash (ni existe un campo "password" en la respuesta).
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->UsuarioId,
            'trabajador_id' => $this->TrabajadorId,
            'nombre' => $this->UsuarioNombre,
            'correo' => $this->UsuarioCorreo,
            'fecha_creacion' => optional($this->UsuarioFechaCreacion)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->UsuarioEstado,
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
        ];
    }
}
