<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seguridad\Usuario;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // GET /api/dashboard?perfil=red|microred|eess|programacion|porteria|personal&anio=&mes=&microred_id=&eess_id=
    // Panel de inicio segun el rol de quien consulta (ver DashboardService). Todo viene limitado a su
    // alcance (su EESS, su microred o toda la Red; el trabajador solo ve lo suyo); `perfil` solo cambia entre los que su
    // rol permite.
    public function resumen(Request $request, DashboardService $servicio): JsonResponse
    {
        $request->validate([
            'perfil' => ['nullable', 'string', 'in:red,microred,eess,programacion,porteria,personal'],
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'agrupar' => ['nullable', 'in:dia,semana,mes'],
            'microred_id' => ['nullable', 'integer'],
            'eess_id' => ['nullable', 'integer'],
        ]);

        /** @var Usuario $usuario */
        $usuario = $request->user();

        return response()->json($servicio->armar($usuario, $request->only(['perfil', 'anio', 'mes', 'agrupar', 'microred_id', 'eess_id'])));
    }
}
