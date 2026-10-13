<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsistenciaCalendarioRequest;
use App\Services\CalendarioAsistenciaPdf;
use App\Services\CalendarioAsistenciaService;
use App\Support\AlcanceDeEess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AsistenciaCalendarioController extends Controller
{
    // GET /api/asistencia-diaria/calendario?eess_id=&anio=&mes=&buscar=
    // Calendario mensual (trabajadores en filas, dias en columnas) de un establecimiento. Es la misma estructura que
    // renderiza el PDF.
    public function mostrar(AsistenciaCalendarioRequest $request, CalendarioAsistenciaService $servicio): JsonResponse
    {
        AlcanceDeEess::exigir($request->integer('eess_id'), $request->user());

        return response()->json($servicio->armar($request->integer('eess_id'), $request->integer('anio'), $request->integer('mes'), $request->input('buscar'), AlcanceDeEess::vinculosPropios($request->user())));
    }

    // GET /api/asistencia-diaria/calendario/pdf?eess_id=&anio=&mes=&buscar=&papel=a4|a3
    // El mismo calendario como documento PDF apaisado.
    public function pdf(AsistenciaCalendarioRequest $request, CalendarioAsistenciaService $servicio, CalendarioAsistenciaPdf $pdf): Response
    {
        AlcanceDeEess::exigir($request->integer('eess_id'), $request->user());

        $calendario = $servicio->armar($request->integer('eess_id'), $request->integer('anio'), $request->integer('mes'), $request->input('buscar'), AlcanceDeEess::vinculosPropios($request->user()));

        return response($pdf->generar($calendario, $request->input('papel', 'a4')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.CalendarioAsistenciaPdf::nombreDeArchivo($calendario).'"',
        ]);
    }
}
