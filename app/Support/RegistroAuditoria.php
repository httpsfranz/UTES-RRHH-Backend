<?php

namespace App\Support;

use App\Models\Seguridad\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deja constancia en Seguridad.Auditoria de lo que cada usuario autenticado crea, modifica o elimina por la API (INSERT,
 * UPDATE, DELETE con los datos anteriores y nuevos). Se engancha a los eventos de Eloquent (AppServiceProvider), asi que
 * cubre todos los modulos sin tocar sus controladores. No registra nada sin un usuario autenticado (seeders, comandos y
 * procesos automaticos), ni los cambios de las propias tablas de seguridad de sesion, auditoria y notificaciones.
 */
class RegistroAuditoria
{
    private const OMITIDAS = ['Seguridad.Auditoria', 'Seguridad.SesionAcceso', 'Soporte.Notificacion', 'Soporte.LogIntegracion'];

    public static function registrar(string $operacion, Model $modelo): void
    {
        $tabla = $modelo->getTable();
        if ($modelo instanceof PersonalAccessToken || in_array($tabla, self::OMITIDAS, true)) {
            return;
        }
        $usuario = auth()->user();
        if ($usuario === null) {
            return;
        }

        [$esquema, $nombre] = str_contains($tabla, '.') ? explode('.', $tabla, 2) : ['dbo', $tabla];
        $anteriores = $operacion === 'INSERT' ? null : ($operacion === 'DELETE' ? $modelo->getAttributes() : array_intersect_key($modelo->getOriginal(), $modelo->getChanges()));
        $nuevos = $operacion === 'DELETE' ? null : ($operacion === 'INSERT' ? $modelo->getAttributes() : $modelo->getChanges());
        if ($operacion === 'UPDATE' && $nuevos === []) {
            return;
        }

        // Insert directo: pasar por el modelo dispararia de nuevo los eventos.
        DB::table('Seguridad.Auditoria')->insert([
            'UsuarioId' => $usuario->getAuthIdentifier(),
            'AuditoriaEsquema' => $esquema,
            'AuditoriaTabla' => $nombre,
            'AuditoriaOperacion' => $operacion,
            'AuditoriaRegistroId' => (string) $modelo->getKey(),
            'AuditoriaDatosAnteriores' => self::json($anteriores),
            'AuditoriaDatosNuevos' => self::json($nuevos),
            'AuditoriaDireccionIp' => request()->ip(),
            'AuditoriaModulo' => self::modulo($esquema),
            'AuditoriaDetalle' => self::motivoDeBaja($operacion),
        ]);
    }

    /**
     * Motivo elegido en el modal de baja del sistema (MotivoBaja viaja con el DELETE o el PATCH que desactiva).
     */
    private static function motivoDeBaja(string $operacion): ?string
    {
        $motivo = trim((string) request()->input('MotivoBaja', ''));

        return $operacion !== 'INSERT' && $motivo !== '' ? mb_substr('Motivo de la desactivación: '.$motivo, 0, 500) : null;
    }

    /**
     * Evento que no es un alta/edicion/baja de un registro: accesos (LOGIN, LOGIN_FALLIDO, LOGOUT), seguridad (BLOQUEO,
     * DESBLOQUEO, ACCESO_DENEGADO, PERMISOS, REVOCAR_SESION, CAMBIO_PASSWORD) y consultas sensibles (EXPORTAR, CONSULTA).
     * Si no hay usuario autenticado (p. ej. un intento de acceso fallido) se indica el usuario afectado.
     */
    public static function evento(string $operacion, string $esquema, string $tabla, ?string $registroId, string $detalle, string $resultado = 'EXITO', ?int $usuarioId = null): void
    {
        DB::table('Seguridad.Auditoria')->insert([
            'UsuarioId' => $usuarioId ?? auth()->user()?->getAuthIdentifier(),
            'AuditoriaEsquema' => $esquema,
            'AuditoriaTabla' => $tabla,
            'AuditoriaOperacion' => $operacion,
            'AuditoriaRegistroId' => $registroId,
            'AuditoriaDireccionIp' => request()->ip(),
            'AuditoriaResultado' => $resultado,
            'AuditoriaModulo' => self::modulo($esquema),
            'AuditoriaDetalle' => mb_substr($detalle, 0, 500),
        ]);
    }

    /** Nombre del modulo del sistema (menu) al que pertenece un esquema de la base. */
    public static function modulo(string $esquema): string
    {
        return [
            'Organizacion' => 'Organización', 'Personal' => 'Personal', 'Seguridad' => 'Seguridad', 'Biometria' => 'Biometría', 'Configuracion' => 'Configuración',
            'Programacion' => 'Programación', 'Asistencia' => 'Asistencia', 'Solicitudes' => 'Solicitudes', 'Soporte' => 'Soporte', 'Vacaciones' => 'Vacaciones',
            'Compensaciones' => 'Compensaciones', 'Consolidacion' => 'Consolidación', 'Disciplina' => 'Disciplina',
        ][$esquema] ?? $esquema;
    }

    /** @param  array<string,mixed>|null  $datos */
    private static function json(?array $datos): ?string
    {
        if ($datos === null) {
            return null;
        }
        $datos = array_filter($datos, fn ($valor, $clave) => ! str_contains(strtolower((string) $clave), 'password'), ARRAY_FILTER_USE_BOTH);

        return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
    }
}
