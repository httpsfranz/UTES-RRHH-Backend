<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarcacionFacialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API del marcador facial (kiosco). Se autentica con su codigo y clave de dispositivo (middleware `dispositivo`), no con
 * una cuenta de usuario.
 */
class MarcadorFacialController extends Controller
{
    // GET /api/marcador-facial/estado  ->  el dispositivo, su establecimiento y la hora del servidor (el reloj del kiosco)
    public function estado(Request $request): JsonResponse
    {
        $dispositivo = $request->attributes->get('dispositivo');

        return response()->json([
            'dispositivo' => ['codigo' => $dispositivo->codigo, 'nombre' => $dispositivo->nombre, 'eess' => $dispositivo->eess],
            // Instante real con zona (HoraLocal es reloj de pared sin zona): el kiosco lo muestra en hora de Lima.
            'hora_servidor' => now()->toIso8601String(),
        ]);
    }

    // POST /api/marcador-facial/marcar  { descriptor: [128 numeros] }
    public function marcar(Request $request, MarcacionFacialService $servicio): JsonResponse
    {
        $datos = $request->validate([
            'descriptor' => ['required', 'array', 'size:'.MarcacionFacialService::DIMENSION],
            'descriptor.*' => ['required', 'numeric', 'between:-1,1'],
        ], [
            'descriptor.*' => 'El descriptor del rostro no es válido.',
        ]);

        return response()->json($servicio->marcar($request->attributes->get('dispositivo'), array_map('floatval', $datos['descriptor'])));
    }
}
