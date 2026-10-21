<?php

namespace App\Http\Middleware;

use App\Support\ClaveDeDispositivo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del marcador facial: en vez de sesion de usuario, el kiosco envia su codigo (X-Dispositivo) y su clave
 * (X-Clave-Dispositivo, ver ClaveDeDispositivo). Solo pasan dispositivos activos de tipo facial; el dispositivo queda en
 * el request (atributo `dispositivo`) para saber su establecimiento.
 */
class DispositivoDeMarcacion
{
    public function handle(Request $request, Closure $next): Response
    {
        $codigo = mb_strtoupper(trim((string) $request->header('X-Dispositivo')));
        if (! ClaveDeDispositivo::valida($codigo, (string) $request->header('X-Clave-Dispositivo'))) {
            return response()->json(['message' => 'El dispositivo no está autorizado: revisa su código y su clave.'], 401);
        }

        $dispositivo = DB::table('Biometria.DispositivoMarcacion as d')
            ->leftJoin('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'd.EessId')
            ->where('d.DispositivoMarcacionCodigo', $codigo)
            ->first(['d.DispositivoMarcacionId as id', 'd.DispositivoMarcacionCodigo as codigo', 'd.DispositivoMarcacionNombre as nombre',
                'd.DispositivoMarcacionTipo as tipo', 'd.DispositivoMarcacionEstado as activo', 'd.EessId as eess_id', 'e.EessNombre as eess']);
        if ($dispositivo === null || ! $dispositivo->activo) {
            return response()->json(['message' => 'El dispositivo no existe o está desactivado en UTES.'], 403);
        }
        if (mb_stripos((string) $dispositivo->tipo, 'facial') === false) {
            return response()->json(['message' => 'El dispositivo no es de reconocimiento facial.'], 403);
        }

        $request->attributes->set('dispositivo', $dispositivo);

        return $next($request);
    }
}
