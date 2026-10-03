<?php

namespace App\Services;

use App\Models\Soporte\Notificacion;

/**
 * Avisos a los usuarios del sistema (Soporte.Notificacion). Los flujos de aprobacion avisan a quien registro
 * la solicitud cuando esta se resuelve.
 */
class NotificacionService
{
    public function notificar(?int $usuarioId, string $tipo, string $titulo, string $mensaje, ?string $enlace = null): ?Notificacion
    {
        if ($usuarioId === null) {
            return null;
        }

        return Notificacion::create([
            'UsuarioId' => $usuarioId,
            'NotificacionTipo' => $tipo,
            'NotificacionTitulo' => mb_substr($titulo, 0, 200),
            'NotificacionMensaje' => mb_substr($mensaje, 0, 1000),
            'NotificacionEnlace' => $enlace,
        ]);
    }
}
