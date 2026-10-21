<?php

namespace App\Services;

use App\Models\Seguridad\SesionAcceso;
use App\Models\Seguridad\Usuario;
use App\Support\HoraLocal;
use App\Support\Parametro;
use App\Support\RegistroAuditoria;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Proteccion de las cuentas de usuario:
 *   - Bloqueo por intentos fallidos (LOGIN_INTENTOS_MAX seguidos -> bloqueada LOGIN_BLOQUEO_MIN minutos) y bloqueo manual
 *     del administrador (sin plazo, hasta que la desbloquee).
 *   - Revocacion de sesiones: al bloquear o desactivar una cuenta se cierran todas sus sesiones abiertas; el administrador
 *     tambien puede revocar una sesion puntual.
 *   - Expiracion: por inactividad (SESION_INACTIVIDAD_MIN) y por vencimiento del token.
 * Cada evento queda en la auditoria con su resultado.
 */
class SeguridadDeCuentaService
{
    public function __construct(private NotificacionService $notificaciones) {}

    /** ¿La cuenta esta bloqueada ahora? Un bloqueo temporal vencido se levanta solo. */
    public function estaBloqueada(Usuario $usuario): bool
    {
        if (! $usuario->UsuarioBloqueado) {
            return false;
        }
        if ($usuario->UsuarioBloqueadoHasta !== null && $usuario->UsuarioBloqueadoHasta <= HoraLocal::ahora()) {
            $usuario->update(['UsuarioBloqueado' => false, 'UsuarioBloqueadoHasta' => null, 'UsuarioMotivoBloqueo' => null, 'UsuarioIntentosFallidos' => 0]);

            return false;
        }

        return true;
    }

    /** Suma un intento fallido; al llegar al maximo bloquea la cuenta por un tiempo. Devuelve true si la bloqueo. */
    public function intentoFallido(Usuario $usuario): bool
    {
        $intentos = $usuario->UsuarioIntentosFallidos + 1;
        $maximo = Parametro::entero('LOGIN_INTENTOS_MAX', 5);
        RegistroAuditoria::evento('LOGIN_FALLIDO', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, "Contraseña incorrecta (intento {$intentos} de {$maximo}).", 'FALLO', $usuario->UsuarioId);

        if ($intentos < $maximo) {
            $usuario->update(['UsuarioIntentosFallidos' => $intentos]);

            return false;
        }

        $minutos = Parametro::entero('LOGIN_BLOQUEO_MIN', 15);
        $this->bloquear($usuario, "Bloqueo automático: {$intentos} intentos fallidos de inicio de sesión.", HoraLocal::ahora()->addMinutes($minutos)->toDateTimeString(), null);

        return true;
    }

    public function accesoExitoso(Usuario $usuario): void
    {
        $usuario->update(['UsuarioIntentosFallidos' => 0, 'UsuarioUltimoAcceso' => HoraLocal::ahora()]);
    }

    /** Bloquea la cuenta (hasta = null: sin plazo) y cierra todas sus sesiones. */
    public function bloquear(Usuario $usuario, string $motivo, ?string $hasta, ?int $porUsuarioId): void
    {
        $usuario->update(['UsuarioBloqueado' => true, 'UsuarioBloqueadoHasta' => $hasta, 'UsuarioMotivoBloqueo' => mb_substr($motivo, 0, 200)]);
        $this->cerrarSesiones($usuario, 'BLOQUEO', $porUsuarioId);
        RegistroAuditoria::evento('BLOQUEO', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, $motivo, 'EXITO', $porUsuarioId ?? $usuario->UsuarioId);
        $this->notificaciones->emitir('CUENTA_BLOQUEADA', 'Cuenta bloqueada: '.$usuario->UsuarioNombre, $motivo, '/seguridad/panel?abrir=usuarios', null, $usuario->UsuarioId);
    }

    public function desbloquear(Usuario $usuario, int $porUsuarioId): void
    {
        $usuario->update(['UsuarioBloqueado' => false, 'UsuarioBloqueadoHasta' => null, 'UsuarioMotivoBloqueo' => null, 'UsuarioIntentosFallidos' => 0]);
        RegistroAuditoria::evento('DESBLOQUEO', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, 'Cuenta desbloqueada.', 'EXITO', $porUsuarioId);
    }

    /** Cierra todas las sesiones abiertas de un usuario (borra sus tokens). */
    public function cerrarSesiones(Usuario $usuario, string $motivo, ?int $porUsuarioId): int
    {
        $abiertas = SesionAcceso::query()->where('UsuarioId', $usuario->UsuarioId)->where('SesionAccesoResultado', 'EXITOSO')->whereNull('SesionAccesoFechaFin')->get();
        foreach ($abiertas as $sesion) {
            $this->cerrar($sesion, $motivo, $porUsuarioId);
        }

        return $abiertas->count();
    }

    /** Cierra una sesion: borra su token y registra cuando, por que y quien. */
    public function cerrar(SesionAcceso $sesion, string $motivo, ?int $porUsuarioId = null): void
    {
        if ($sesion->SesionAccesoFechaFin !== null) {
            return;
        }
        // Revocada o por bloqueo, el token se borra en su proximo uso (SesionActiva), para responder al usuario el motivo.
        if (! in_array($motivo, ['REVOCADA', 'BLOQUEO'], true)) {
            PersonalAccessToken::query()->where('tokenable_id', $sesion->UsuarioId)->where('name', 'sesion-'.$sesion->SesionAccesoId)->delete();
        }
        $sesion->update(['SesionAccesoFechaFin' => HoraLocal::ahora(), 'SesionAccesoMotivoCierre' => $motivo, 'SesionAccesoRevocadaPor' => $motivo === 'REVOCADA' || $motivo === 'BLOQUEO' ? $porUsuarioId : null]);
        if ($motivo === 'REVOCADA') {
            RegistroAuditoria::evento('REVOCAR_SESION', 'Seguridad', 'SesionAcceso', (string) $sesion->SesionAccesoId, 'Sesión revocada por el administrador.', 'EXITO', $porUsuarioId);
        }
    }

    /**
     * Comprueba la sesion del token en cada peticion: si paso el tiempo de inactividad o vencio, la cierra y devuelve el
     * motivo; si sigue viva, registra la actividad (como maximo una escritura por minuto).
     */
    public function verificarActividad(PersonalAccessToken $token): ?string
    {
        if (! preg_match('/^sesion-(\d+)$/', (string) $token->name, $m)) {
            return null;
        }
        $sesion = SesionAcceso::query()->find((int) $m[1]);
        if ($sesion === null) {
            return null;
        }
        $ahora = HoraLocal::ahora();
        if ($sesion->SesionAccesoFechaFin !== null) {
            $token->delete();

            return $sesion->SesionAccesoMotivoCierre === 'BLOQUEO' ? 'BLOQUEO' : 'REVOCADA';
        }
        $ultima = $sesion->SesionAccesoUltimaActividad ?? $sesion->SesionAccesoFechaInicio;
        $limite = Parametro::entero('SESION_INACTIVIDAD_MIN', 30);
        if ($ultima !== null && $ultima->addMinutes($limite) < $ahora) {
            $this->cerrar($sesion, 'INACTIVIDAD');

            return 'INACTIVIDAD';
        }
        if ($sesion->SesionAccesoExpiraEn !== null && $sesion->SesionAccesoExpiraEn < $ahora) {
            $this->cerrar($sesion, 'EXPIRADA');

            return 'EXPIRADA';
        }
        if ($ultima === null || $ultima->diffInSeconds($ahora) >= 60) {
            DB::table('Seguridad.SesionAcceso')->where('SesionAccesoId', $sesion->SesionAccesoId)->update(['SesionAccesoUltimaActividad' => $ahora->toDateTimeString()]);
        }

        return null;
    }
}
