<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BandejaDeSolicitudesService;
use App\Services\PersonalSinHorarioService;
use App\Support\AccesoUsuario;
use App\Support\AlcanceDeEess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContadoresDelMenuController extends Controller
{
    // GET /api/menu-pendientes  ->  { "/solicitudes/bandeja": 12, "/personal/trabajadores": 3 }
    // Lo que espera al usuario en cada entrada del menu (ruta de la pantalla => cantidad). Solo trae lo que puede ver.
    public function __invoke(Request $request, BandejaDeSolicitudesService $bandeja, PersonalSinHorarioService $sinHorario): JsonResponse
    {
        $usuario = $request->user();
        $acceso = AccesoUsuario::de($usuario);

        $contadores = ['/solicitudes/bandeja' => $bandeja->cantidadPendiente($usuario)];
        if (! $acceso->propio && $acceso->tiene('PERSONAL_VER')) {
            $contadores['/personal/trabajadores'] = $sinHorario->cantidad(AlcanceDeEess::permitidos($usuario));
        }

        return response()->json($contadores);
    }
}
