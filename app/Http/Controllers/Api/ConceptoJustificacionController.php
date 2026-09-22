<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConceptoJustificacionRequest;
use App\Http\Resources\ConceptoJustificacionResource;
use App\Models\Asistencia\ConceptoJustificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConceptoJustificacionController extends Controller
{
    // GET /api/conceptos-justificacion?buscar=duelo&estado=1&requiere_documento=0&es_remunerado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $conceptos = ConceptoJustificacion::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('ConceptoJustificacionNombre', 'like', "%{$buscar}%")
                    ->orWhere('ConceptoJustificacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('ConceptoJustificacionEstado', $request->boolean('estado')))
            ->when($request->filled('requiere_documento'), fn ($q) =>
                $q->where('ConceptoJustificacionRequiereDocumento', $request->boolean('requiere_documento')))
            ->when($request->filled('es_remunerado'), fn ($q) =>
                $q->where('ConceptoJustificacionEsRemunerado', $request->boolean('es_remunerado')))
            ->orderBy('ConceptoJustificacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ConceptoJustificacionResource::collection($conceptos);
    }

    // POST /api/conceptos-justificacion
    public function store(ConceptoJustificacionRequest $request): JsonResponse
    {
        $concepto = ConceptoJustificacion::create($request->validated());

        // fresh(): trae los valores que la base asigno por DEFAULT (id, BITs omitidos).
        return (new ConceptoJustificacionResource($concepto->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/conceptos-justificacion/{concepto}
    public function show(ConceptoJustificacion $concepto)
    {
        return new ConceptoJustificacionResource($concepto);
    }

    // PUT|PATCH /api/conceptos-justificacion/{concepto}
    public function update(ConceptoJustificacionRequest $request, ConceptoJustificacion $concepto)
    {
        $concepto->update($request->validated());

        return new ConceptoJustificacionResource($concepto->fresh());
    }

    // DELETE /api/conceptos-justificacion/{concepto}
    public function destroy(ConceptoJustificacion $concepto): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Asistencia.JustificacionFalta tiene una FK hacia esta tabla.
        $concepto->update(['ConceptoJustificacionEstado' => 0]);

        return response()->json(['mensaje' => 'Concepto de justificación desactivado.'], 200);
    }
}
