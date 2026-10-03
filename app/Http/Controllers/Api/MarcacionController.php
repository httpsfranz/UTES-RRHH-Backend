<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarcacionRequest;
use App\Http\Resources\MarcacionResource;
use App\Models\Asistencia\Marcacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarcacionController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'metodo:MetodoMarcacionId,MetodoMarcacionCodigo,MetodoMarcacionNombre',
        'dispositivo:DispositivoMarcacionId,DispositivoMarcacionCodigo,DispositivoMarcacionNombre',
    ];

    // GET /api/marcaciones?buscar=...&vinculo_laboral_id=&metodo_marcacion_id=&dispositivo_marcacion_id=&carga_asistencia_manual_id=&tipo=&estado=&valida=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = Marcacion::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('MarcacionObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('metodo_marcacion_id'), fn ($q) => $q->where('MetodoMarcacionId', $request->integer('metodo_marcacion_id')))
            ->when($request->filled('dispositivo_marcacion_id'), fn ($q) => $q->where('DispositivoMarcacionId', $request->integer('dispositivo_marcacion_id')))
            ->when($request->filled('carga_asistencia_manual_id'), fn ($q) => $q->where('CargaAsistenciaManualId', $request->integer('carga_asistencia_manual_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('MarcacionTipo', $request->string('tipo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('MarcacionEsValida', $request->boolean('estado')))
            ->when($request->filled('valida'), fn ($q) => $q->where('MarcacionEsValida', $request->boolean('valida')))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('MarcacionFechaHora', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('MarcacionFechaHora', '<=', $request->date('hasta')))
            ->orderByDesc('MarcacionFechaHora')
            ->orderByDesc('MarcacionId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return MarcacionResource::collection($registros);
    }

    // POST /api/marcaciones
    public function store(MarcacionRequest $request): JsonResponse
    {
        $marcacion = Marcacion::create($request->datos());

        return (new MarcacionResource($marcacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/marcaciones/{marcacion}
    public function show(Marcacion $marcacion)
    {
        return new MarcacionResource($marcacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/marcaciones/{marcacion}
    public function update(MarcacionRequest $request, Marcacion $marcacion)
    {
        $marcacion->update($request->datos());

        return new MarcacionResource($marcacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/marcaciones/{marcacion}
    public function destroy(Marcacion $marcacion): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial se conserva.
        $marcacion->update(['MarcacionEsValida' => false]);

        return response()->json(['mensaje' => 'Marcación invalidada.'], 200);
    }
}
