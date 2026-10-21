<?php

namespace App\Services;

use App\Models\Seguridad\Usuario;
use App\Models\Soporte\Notificacion;
use App\Models\Soporte\TipoNotificacion;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos a los usuarios del sistema (Soporte.Notificacion): bandeja interna y, si el tipo lo pide, correo.
 *
 *   - notificar(): un aviso a un usuario concreto (p. ej. a quien registro una solicitud cuando se resuelve).
 *   - emitir(): un evento del sistema; los destinatarios salen de la regla del tipo (Soporte.TipoNotificacion): el
 *     interesado y los usuarios con los roles de destino cuyo ambito cubre el establecimiento del evento.
 *
 * Correo: la notificacion queda PENDIENTE y se envia al terminar la respuesta; si falla se reintenta (comando
 * notificaciones:enviar, cada minuto) hasta MAX_INTENTOS, y despues queda FALLIDO con el error. Sin servidor SMTP
 * configurado el mailer es "log": el correo se escribe en storage/logs.
 */
class NotificacionService
{
    public const MAX_INTENTOS = 3;

    public function notificar(?int $usuarioId, string $tipo, string $titulo, string $mensaje, ?string $enlace = null): ?Notificacion
    {
        if ($usuarioId === null) {
            return null;
        }
        $regla = TipoNotificacion::query()->where('TipoNotificacionCodigo', $tipo)->first();
        if ($regla !== null && ! $regla->TipoNotificacionEstado) {
            return null;
        }
        $correo = $regla?->TipoNotificacionEnviaCorreo && filled(DB::table('Seguridad.Usuario')->where('UsuarioId', $usuarioId)->value('UsuarioCorreo'));

        $notificacion = Notificacion::create([
            'UsuarioId' => $usuarioId,
            'NotificacionTipo' => $tipo,
            'NotificacionTitulo' => mb_substr($titulo, 0, 200),
            'NotificacionMensaje' => mb_substr($mensaje, 0, 1000),
            'NotificacionEnlace' => $enlace,
            'NotificacionCorreoEstado' => $correo ? 'PENDIENTE' : 'NO_APLICA',
        ]);

        if ($correo) {
            $id = $notificacion->NotificacionId;
            dispatch(fn () => app(self::class)->enviarCorreo($id))->afterResponse();
        }

        return $notificacion;
    }

    /**
     * Evento del sistema: avisa segun la regla del tipo. Devuelve cuantas notificaciones se crearon.
     *
     * @param  int|null  $eessId  establecimiento del evento (define el ambito de los destinatarios)
     * @param  int|null  $interesadoId  usuario directamente afectado (p. ej. el trabajador)
     */
    public function emitir(string $tipo, string $titulo, string $mensaje, ?string $enlace = null, ?int $eessId = null, ?int $interesadoId = null): int
    {
        $regla = TipoNotificacion::query()->where('TipoNotificacionCodigo', $tipo)->first();
        if ($regla === null || ! $regla->TipoNotificacionEstado) {
            return $interesadoId !== null && $regla === null ? (int) (bool) $this->notificar($interesadoId, $tipo, $titulo, $mensaje, $enlace) : 0;
        }

        $destinatarios = $this->destinatarios($regla, $eessId);
        if ($regla->TipoNotificacionAlInteresado && $interesadoId !== null) {
            $destinatarios[] = $interesadoId;
        }

        $creadas = 0;
        foreach (array_unique($destinatarios) as $usuarioId) {
            $creadas += $this->notificar($usuarioId, $tipo, $titulo, $mensaje, $enlace) ? 1 : 0;
        }

        return $creadas;
    }

    /** @return list<int> usuarios activos con un rol de destino y ambito sobre el establecimiento */
    public function destinatarios(TipoNotificacion $regla, ?int $eessId): array
    {
        $roles = $regla->roles();
        if ($roles === [] || $regla->TipoNotificacionAlcance === 'INTERESADO') {
            return [];
        }
        $microred = $eessId ? DB::table('Organizacion.EstablecimientoSalud')->where('EessId', $eessId)->value('MicroredId') : null;
        $hoy = HoraLocal::hoy()->toDateString();

        $usuarios = Usuario::query()->where('UsuarioEstado', 1)->where('UsuarioBloqueado', 0)
            ->whereIn('UsuarioId', DB::table('Seguridad.UsuarioRol as ur')->join('Seguridad.Rol as r', 'r.RolId', '=', 'ur.RolId')
                ->whereIn('r.RolCodigo', $roles)->where('ur.UsuarioRolEstado', 1)
                ->where(fn ($q) => $q->whereNull('ur.UsuarioRolFechaFin')->orWhereDate('ur.UsuarioRolFechaFin', '>=', $hoy))
                ->select('ur.UsuarioId'))
            ->get();

        return $usuarios->filter(function (Usuario $u) use ($regla, $eessId, $microred) {
            $permitidos = AccesoUsuario::de($u)->eessIds;
            if ($permitidos === null || $regla->TipoNotificacionAlcance === 'RED' || $eessId === null) {
                return true;
            }
            if ($regla->TipoNotificacionAlcance === 'MICRORED' && $microred !== null) {
                return DB::table('Organizacion.EstablecimientoSalud')->where('MicroredId', $microred)->whereIn('EessId', $permitidos)->exists();
            }

            return in_array($eessId, $permitidos, true);
        })->pluck('UsuarioId')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Envia (o reintenta) el correo de una notificacion y registra el resultado de la entrega. */
    public function enviarCorreo(int $notificacionId): void
    {
        $n = Notificacion::query()->find($notificacionId);
        if ($n === null || ! in_array($n->NotificacionCorreoEstado, ['PENDIENTE', 'FALLIDO'], true) || $n->NotificacionCorreoIntentos >= self::MAX_INTENTOS) {
            return;
        }
        $correo = DB::table('Seguridad.Usuario')->where('UsuarioId', $n->UsuarioId)->value('UsuarioCorreo');
        $intentos = $n->NotificacionCorreoIntentos + 1;

        try {
            if (blank($correo)) {
                throw new \RuntimeException('El usuario no tiene correo registrado.');
            }
            Mail::raw($n->NotificacionMensaje."\n\n— Sistema de Control de Asistencia, Red de Salud Trujillo", function ($m) use ($correo, $n) {
                $m->to($correo)->subject($n->NotificacionTitulo);
            });
            $n->update(['NotificacionCorreoEstado' => 'ENVIADO', 'NotificacionCorreoIntentos' => $intentos, 'NotificacionCorreoFechaEnvio' => HoraLocal::ahora(), 'NotificacionCorreoError' => null]);
        } catch (Throwable $e) {
            $n->update([
                'NotificacionCorreoEstado' => $intentos >= self::MAX_INTENTOS ? 'FALLIDO' : 'PENDIENTE',
                'NotificacionCorreoIntentos' => $intentos,
                'NotificacionCorreoError' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /** Reintenta los correos pendientes (comando notificaciones:enviar). Devuelve cuantos se procesaron. */
    public function enviarPendientes(): int
    {
        $ids = Notificacion::query()->where('NotificacionCorreoEstado', 'PENDIENTE')->where('NotificacionCorreoIntentos', '<', self::MAX_INTENTOS)
            ->orderBy('NotificacionId')->limit(200)->pluck('NotificacionId');
        $ids->each(fn ($id) => $this->enviarCorreo((int) $id));

        return $ids->count();
    }
}
