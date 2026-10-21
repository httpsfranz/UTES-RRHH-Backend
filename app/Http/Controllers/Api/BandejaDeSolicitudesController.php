<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BandejaDeSolicitudesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BandejaDeSolicitudesController extends Controller
{
    // GET /api/bandeja-solicitudes?estado=PENDIENTE|TODOS&tipo=papeletas
    // Todas las solicitudes por aprobar que la sesion puede ver (o, si solo es TRABAJADOR, las suyas). Ver el servicio.
    public function index(Request $request, BandejaDeSolicitudesService $servicio): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', Rule::in(['PENDIENTE', 'TODOS'])],
            'tipo' => ['nullable', Rule::in(array_keys(BandejaDeSolicitudesService::TIPOS))],
        ]);

        return response()->json($servicio->listar($request->user(), $datos['estado'] ?? 'PENDIENTE', $datos['tipo'] ?? null));
    }
}
