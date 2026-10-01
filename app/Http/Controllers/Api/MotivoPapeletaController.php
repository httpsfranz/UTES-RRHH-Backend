<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MotivoPapeletaRequest;
use App\Http\Resources\MotivoPapeletaResource;
use App\Models\Solicitudes\MotivoPapeleta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MotivoPapeletaController extends Controller
{
    // GET /api/motivos-papeleta?buscar=reunion&tipo_papeleta_id=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $motivos = MotivoPapeleta::query()
            ->with('tipoPapeleta:TipoPapeletaId,TipoPapeletaCodigo,TipoPapeletaNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('MotivoPapeletaNombre', 'like', "%{$buscar}%")
                ->orWhere('MotivoPapeletaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('tipo_papeleta_id'), fn ($q) => $q->where('TipoPapeletaId', $request->integer('tipo_papeleta_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('MotivoPapeletaEstado', $request->boolean('estado')))
            ->orderBy('TipoPapeletaId')
            ->orderBy('MotivoPapeletaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return MotivoPapeletaResource::collection($motivos);
    }

    // POST /api/motivos-papeleta
    public function store(MotivoPapeletaRequest $request): JsonResponse
    {
        $motivo = MotivoPapeleta::create($request->validated());

        return (new MotivoPapeletaResource($motivo->fresh()->load('tipoPapeleta')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/motivos-papeleta/{motivo}
    public function show(MotivoPapeleta $motivo)
    {
        return new MotivoPapeletaResource($motivo->load('tipoPapeleta'));
    }

    // PUT|PATCH /api/motivos-papeleta/{motivo}
    public function update(MotivoPapeletaRequest $request, MotivoPapeleta $motivo)
    {
        $motivo->update($request->validated());

        return new MotivoPapeletaResource($motivo->fresh()->load('tipoPapeleta'));
    }

    // DELETE /api/motivos-papeleta/{motivo}
    public function destroy(MotivoPapeleta $motivo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Solicitudes.Papeleta referencia el motivo.
        $motivo->update(['MotivoPapeletaEstado' => false]);

        return response()->json(['mensaje' => 'Motivo de papeleta desactivado.'], 200);
    }
}
