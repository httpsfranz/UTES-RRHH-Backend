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
            'fecha_lectura' => optional($this->NotificacionFechaLectura)->format('Y-m-d H:i:s'),
            // Entrega por correo: NO_APLICA (solo bandeja), PENDIENTE, ENVIADO o FALLIDO (con el error del ultimo intento).
            'correo_estado' => $this->NotificacionCorreoEstado,
            'correo_intentos' => (int) $this->NotificacionCorreoIntentos,
            'correo_fecha_envio' => optional($this->NotificacionCorreoFechaEnvio)->format('Y-m-d H:i:s'),
            'correo_error' => $this->NotificacionCorreoError,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
        ];
    }
}
