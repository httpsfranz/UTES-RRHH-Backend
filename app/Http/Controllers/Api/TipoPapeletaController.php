<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoPapeletaRequest;
use App\Http\Resources\TipoPapeletaResource;
use App\Models\Solicitudes\TipoPapeleta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoPapeletaController extends Controller
{
    // GET /api/tipos-papeleta?buscar=salida&estado=1&es_descontable=0&requiere_sustento=1&afecta_jornada=1&es_compensable=0&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoPapeleta::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoPapeletaNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoPapeletaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoPapeletaEstado', $request->boolean('estado')))
            ->when($request->filled('es_descontable'), fn ($q) =>
                $q->where('TipoPapeletaEsDescontable', $request->boolean('es_descontable')))
            ->when($request->filled('requiere_sustento'), fn ($q) =>
                $q->where('TipoPapeletaRequiereSustento', $request->boolean('requiere_sustento')))
            ->when($request->filled('afecta_jornada'), fn ($q) =>
                $q->where('TipoPapeletaAfectaJornada', $request->boolean('afecta_jornada')))
            ->when($request->filled('es_compensable'), fn ($q) =>
                $q->where('TipoPapeletaEsCompensable', $request->boolean('es_compensable')))
            ->orderBy('TipoPapeletaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoPapeletaResource::collection($tipos);
    }

    // POST /api/tipos-papeleta
    public function store(TipoPapeletaRequest $request): JsonResponse
    {
        $tipo = TipoPapeleta::create($request->validated());

        return (new TipoPapeletaResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-papeleta/{tipo}
    public function show(TipoPapeleta $tipo)
    {
        return new TipoPapeletaResource($tipo);
    }

    // PUT|PATCH /api/tipos-papeleta/{tipo}
    public function update(TipoPapeletaRequest $request, TipoPapeleta $tipo)
    {
        $tipo->update($request->validated());

        return new TipoPapeletaResource($tipo->fresh());
    }

    // DELETE /api/tipos-papeleta/{tipo}
    public function destroy(TipoPapeleta $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Solicitudes.Papeleta tiene una FK hacia esta tabla.
        $tipo->update(['TipoPapeletaEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de papeleta desactivado.'], 200);
    }
}
