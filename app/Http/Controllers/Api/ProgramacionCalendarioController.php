<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsistenciaCalendarioRequest;
use App\Services\CalendarioProgramacionService;
use App\Support\AlcanceDeEess;
use Illuminate\Http\JsonResponse;

class ProgramacionCalendarioController extends Controller
{
    // GET /api/programacion-calendario/turnos?eess_id=&anio=&mes=&buscar=
    // Turnos programados del mes (trabajadores en filas, dias en columnas), con borradores, guardias y conflictos.
    public function turnos(AsistenciaCalendarioRequest $request, CalendarioProgramacionService $servicio): JsonResponse
    {
        AlcanceDeEess::exigir($request->integer('eess_id'), $request->user());

        return response()->json($servicio->programacion($request->integer('eess_id'), $request->integer('anio'), $request->integer('mes'), $request->input('buscar'), AlcanceDeEess::vinculosPropios($request->user())));
    }

    // GET /api/programacion-calendario/ausencias?eess_id=&anio=&mes=&buscar=
    // Licencias, descansos medicos, papeletas y vacaciones aprobados del mes.
    public function ausencias(AsistenciaCalendarioRequest $request, CalendarioProgramacionService $servicio): JsonResponse
    {
        AlcanceDeEess::exigir($request->integer('eess_id'), $request->user());

        return response()->json($servicio->ausencias($request->integer('eess_id'), $request->integer('anio'), $request->integer('mes'), $request->input('buscar'), AlcanceDeEess::vinculosPropios($request->user())));
    }
}
