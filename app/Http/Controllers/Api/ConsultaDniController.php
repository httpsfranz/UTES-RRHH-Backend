<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConsultaDni\ConsultaDniService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ConsultaDniController extends Controller
{
    // GET /api/consulta-dni/{dni}
    // Datos de la persona para autocompletar el alta de un trabajador. Responde 200 aunque el servicio externo falle
    // (`disponible: false`): el formulario no depende de el.
    public function mostrar(string $dni, ConsultaDniService $servicio): JsonResponse
    {
        Validator::make(['dni' => $dni], ['dni' => ['required', 'regex:/^\d{8}$/D']], ['dni.regex' => 'El DNI debe tener 8 dígitos.'])->validate();

        return response()->json($servicio->consultar($dni));
    }
}
