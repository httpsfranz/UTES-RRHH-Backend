<?php

namespace App\Http\Middleware;

use App\Services\SeguridadDeCuentaService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Va despues de auth:sanctum: cierra la sesion que paso el tiempo de inactividad, que vencio o que fue revocada (por el
 * administrador o por un bloqueo de la cuenta) y responde 401 con el motivo; si sigue viva, registra la actividad.
 */
class SesionActiva
{
    private const MENSAJES = [
        'INACTIVIDAD' => 'Tu sesión expiró por inactividad. Ingresa de nuevo.',
        'EXPIRADA' => 'Tu sesión venció. Ingresa de nuevo.',
        'REVOCADA' => 'Tu sesión fue cerrada por el administrador. Ingresa de nuevo.',
        'BLOQUEO' => 'Tu cuenta fue bloqueada y tu sesión se cerró. Comunícate con el administrador.',
    ];

    public function __construct(private SeguridadDeCuentaService $seguridad) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $motivo = $this->seguridad->verificarActividad($token);
            if ($motivo !== null) {
                return response()->json(['message' => self::MENSAJES[$motivo], 'motivo' => $motivo], 401);
            }
        }

        // Clave asignada por el administrador: hasta cambiarla solo se usan las rutas de la propia sesion.
        if ($request->user()?->UsuarioDebeCambiarPassword && ! $request->is('api/auth/*')) {
            return response()->json(['message' => 'Debes cambiar tu contraseña para continuar.', 'motivo' => 'CAMBIAR_CLAVE'], 403);
        }

        return $next($request);
    }
}
