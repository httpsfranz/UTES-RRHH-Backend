<?php

namespace App\Http\Middleware;

use App\Support\AccesoUsuario;
use App\Support\PermisosDeRuta;
use App\Support\RegistroAuditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige el permiso que PermisosDeRuta asigna a la ruta. Va despues de auth:sanctum. Tambien fija quien actua en las
 * operaciones de escritura: el usuario que aprueba, rechaza o registra es SIEMPRE el autenticado, nunca el que diga el
 * cuerpo de la peticion (asi nadie aprueba "a nombre de" otro).
 */
class VerificarPermiso
{
    /** Recursos cuyo POST de creacion lleva el usuario que registra en UsuarioId. */
    private const REGISTRAN_CON_USUARIO_ID = ['ajustes-marcacion', 'cargas-asistencia-manual', 'supervisiones-inopinadas', 'ocurrencias-porteria'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $segmentos = array_slice($request->segments(), 1);
        $permiso = PermisosDeRuta::para($request->method(), $segmentos);

        if ($permiso === '') {
            return response()->json(['message' => 'La ruta solicitada no existe.'], 404);
        }
        $acceso = AccesoUsuario::de($usuario);
        if ($permiso !== null && ! $acceso->tiene($permiso)) {
            $this->registrarDenegado($request, "Sin el permiso {$permiso}.");

            return response()->json(['message' => 'No tienes permiso para realizar esta operación.'], 403);
        }
        if ($permiso !== null && $acceso->propio && ! in_array($segmentos[0] ?? '', PermisosDeRuta::RECURSOS_PROPIOS, true)) {
            $this->registrarDenegado($request, 'Información fuera de su alcance (solo consulta lo propio).');

            return response()->json(['message' => 'No tienes permiso para consultar esta información.'], 403);
        }

        $this->fijarUsuarioActuante($request, $segmentos, (int) $usuario->UsuarioId);

        return $next($request);
    }

    /** Evento de seguridad: acceso denegado, con la ruta que se intento. */
    private function registrarDenegado(Request $request, string $motivo): void
    {
        RegistroAuditoria::evento('ACCESO_DENEGADO', 'Seguridad', 'Ruta', null, $request->method().' /'.$request->path().' · '.$motivo, 'DENEGADO');
    }

    /** @param  list<string>  $segmentos */
    private function fijarUsuarioActuante(Request $request, array $segmentos, int $usuarioId): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return;
        }
        $recurso = $segmentos[0] ?? '';
        $accion = end($segmentos);
        $esResolucion = $request->isMethod('POST') && in_array($accion, ['aprobar', 'rechazar', 'devolver'], true);

        if ($esResolucion) {
            $request->merge(['UsuarioId' => $usuarioId]);

            return;
        }
        if ($request->isMethod('POST') && count($segmentos) === 1) {
            $request->merge(['UsuarioRegistroId' => $usuarioId]);
            if (in_array($recurso, self::REGISTRAN_CON_USUARIO_ID, true)) {
                $request->merge(['UsuarioId' => $usuarioId]);
            }
        }
    }
}
