<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstadoAsistenciaRequest;
use App\Http\Resources\EstadoAsistenciaResource;
use App\Models\Asistencia\EstadoAsistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstadoAsistenciaController extends Controller
{
    // GET /api/estados-asistencia?buscar=falta&estado=1&es_falta=1&es_descontable=0&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $estados = EstadoAsistencia::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('EstadoAsistenciaNombre', 'like', "%{$buscar}%")
                ->orWhere('EstadoAsistenciaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) => $q->where('EstadoAsistenciaEstado', $request->boolean('estado')))
            ->when($request->filled('es_falta'), fn ($q) => $q->where('EstadoAsistenciaEsFalta', $request->boolean('es_falta')))
            ->when($request->filled('es_descontable'), fn ($q) => $q->where('EstadoAsistenciaEsDescontable', $request->boolean('es_descontable')))
            ->orderBy('EstadoAsistenciaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return EstadoAsistenciaResource::collection($estados);
    }

    // POST /api/estados-asistencia
    public function store(EstadoAsistenciaRequest $request): JsonResponse
    {
        $estado = EstadoAsistencia::create($request->validated());

        return (new EstadoAsistenciaResource($estado->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/estados-asistencia/{estadoAsistencia}
    public function show(EstadoAsistencia $estadoAsistencia)
    {
        return new EstadoAsistenciaResource($estadoAsistencia);
    }

    // PUT|PATCH /api/estados-asistencia/{estadoAsistencia}
    public function update(EstadoAsistenciaRequest $request, EstadoAsistencia $estadoAsistencia)
    {
        $estadoAsistencia->update($request->validated());

        return new EstadoAsistenciaResource($estadoAsistencia->fresh());
    }

    // DELETE /api/estados-asistencia/{estadoAsistencia}
    public function destroy(EstadoAsistencia $estadoAsistencia): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Asistencia.AsistenciaDiaria tiene una FK hacia esta tabla.
        $estadoAsistencia->update(['EstadoAsistenciaEstado' => false]);

        return response()->json(['mensaje' => 'Estado de asistencia desactivado.'], 200);
    }
}
