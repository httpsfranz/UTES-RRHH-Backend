<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->NotificacionId,
            'usuario_id' => $this->UsuarioId,
            'tipo' => $this->NotificacionTipo,
            'titulo' => $this->NotificacionTitulo,
            'mensaje' => $this->NotificacionMensaje,
            'enlace' => $this->NotificacionEnlace,
            'fecha' => optional($this->NotificacionFecha)->format('Y-m-d H:i:s'),
            'leida' => (bool) $this->NotificacionLeida,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
        ];
    }
}
