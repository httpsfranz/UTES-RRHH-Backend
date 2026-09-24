<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ColegiaturaTipoRequest;
use App\Http\Resources\ColegiaturaTipoResource;
use App\Models\Personal\ColegiaturaTipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColegiaturaTipoController extends Controller
{
    // GET /api/tipos-colegiatura?buscar=medic&profesion_id=1&estado=1
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = ColegiaturaTipo::query()
            ->with('profesion:ProfesionId,ProfesionNombre')
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('ColegiaturaTipoNombre', 'like', "%{$buscar}%")
                    ->orWhere('ColegiaturaTipoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('profesion_id'), fn ($q) =>
                $q->where('ProfesionId', $request->integer('profesion_id')))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('ColegiaturaTipoEstado', $request->boolean('estado')))
            ->orderBy('ColegiaturaTipoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ColegiaturaTipoResource::collection($tipos);
    }

    // POST /api/tipos-colegiatura
    public function store(ColegiaturaTipoRequest $request): JsonResponse
    {
        $tipo = ColegiaturaTipo::create($request->validated());

        return (new ColegiaturaTipoResource($tipo->fresh()->load('profesion')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-colegiatura/{tipo}
    public function show(ColegiaturaTipo $tipo)
    {
        return new ColegiaturaTipoResource($tipo->load('profesion'));
    }

    // PUT|PATCH /api/tipos-colegiatura/{tipo}
    public function update(ColegiaturaTipoRequest $request, ColegiaturaTipo $tipo)
    {
        $tipo->update($request->validated());

        return new ColegiaturaTipoResource($tipo->fresh()->load('profesion'));
    }

    // DELETE /api/tipos-colegiatura/{tipo}
    public function destroy(ColegiaturaTipo $tipo): JsonResponse
    {
        // BAJA LOGICA: Personal.Colegiatura referencia ColegiaturaTipoId.
        $tipo->update(['ColegiaturaTipoEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de colegiatura desactivado.'], 200);
    }
}
