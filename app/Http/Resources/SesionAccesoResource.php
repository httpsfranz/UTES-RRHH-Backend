<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SesionAccesoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->SesionAccesoId,
            'usuario_id' => $this->UsuarioId,
            'fecha_inicio' => optional($this->SesionAccesoFechaInicio)->format('Y-m-d H:i:s'),
            'fecha_fin' => optional($this->SesionAccesoFechaFin)->format('Y-m-d H:i:s'),
            'direccion_ip' => $this->SesionAccesoDireccionIp,
            'resultado' => $this->SesionAccesoResultado,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
        ];
    }
}
