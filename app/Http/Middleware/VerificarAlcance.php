<?php

namespace App\Http\Middleware;

use App\Support\AlcanceDeRegistro;
use App\Support\RegistroAuditoria;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Va despues de "permiso": comprueba que cada registro de la ruta, y cada trabajador o establecimiento enviado en la
 * peticion, este dentro del alcance del usuario. Sin esto, cambiar el id en la direccion abriria datos de otro
 * establecimiento o de otra persona. Ver AlcanceDeRegistro.
 */
class VerificarAlcance
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        foreach ($request->route()?->parameters() ?? [] as $parametro) {
            if ($parametro instanceof Model && ! AlcanceDeRegistro::puedeVer($parametro, $usuario)) {
                return $this->denegar($request, class_basename($parametro).' '.$parametro->getKey());
            }
        }
        if (! $request->isMethodSafe() && ($campos = AlcanceDeRegistro::camposFueraDeAlcance($request, $usuario)) !== []) {
            return $this->denegar($request, implode(', ', $campos));
        }

        return $next($request);
    }

    private function denegar(Request $request, string $detalle): Response
    {
        RegistroAuditoria::evento('ACCESO_DENEGADO', 'Seguridad', 'Ruta', null, $request->method().' /'.$request->path().' · Fuera de su alcance: '.$detalle.'.', 'DENEGADO');

        return response()->json(['message' => 'Este registro no pertenece a tu establecimiento o a tu alcance.'], 403);
    }
}
