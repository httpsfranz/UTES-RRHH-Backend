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
        ]);
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
