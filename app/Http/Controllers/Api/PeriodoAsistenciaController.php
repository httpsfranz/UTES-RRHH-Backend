<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodoAsistenciaRequest;
use App\Http\Resources\PeriodoAsistenciaResource;
use App\Models\Consolidacion\PeriodoAsistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodoAsistenciaController extends Controller
{
    // GET /api/periodos-asistencia?anio=2026&mes=9&estado=ABIERTO
    public function index(Request $request)
    {
        $periodos = PeriodoAsistencia::query()
            ->when($request->filled('anio'), fn ($q) =>
                $q->where('PeriodoAsistenciaAnio', $request->integer('anio')))
            ->when($request->filled('mes'), fn ($q) =>
                $q->where('PeriodoAsistenciaMes', $request->integer('mes')))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('PeriodoAsistenciaEstado', $request->string('estado')->toString()))
            ->orderByDesc('PeriodoAsistenciaAnio')
            ->orderByDesc('PeriodoAsistenciaMes')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return PeriodoAsistenciaResource::collection($periodos);
    }

    // POST /api/periodos-asistencia
    public function store(PeriodoAsistenciaRequest $request): JsonResponse
    {
        // PeriodoAsistenciaEstado tiene DEFAULT ('ABIERTO'): si no llega, la base lo asigna.
        $periodo = PeriodoAsistencia::create($request->validated());

        return (new PeriodoAsistenciaResource($periodo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/periodos-asistencia/{periodo}
    public function show(PeriodoAsistencia $periodo)
    {
        return new PeriodoAsistenciaResource($periodo);
    }

    // PUT|PATCH /api/periodos-asistencia/{periodo}
    public function update(PeriodoAsistenciaRequest $request, PeriodoAsistencia $periodo)
    {
        $periodo->update($request->validated());

        return new PeriodoAsistenciaResource($periodo->fresh());
    }

    // No hay destroy(): un periodo no se "desactiva" (su Estado es un ciclo de vida
    // ABIERTO -> EN_PROCESO -> CERRADO, no un booleano) ni se borra una vez que
    // Consolidacion.ConsolidadoAsistencia depende de el. El cierre real de un periodo
    // es una transicion de negocio que le corresponde a un Service cuando ese modulo
    // se construya, no a un DELETE HTTP.
}
