<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfesionRequest;
use App\Http\Resources\ProfesionResource;
use App\Models\Personal\Profesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfesionController extends Controller
{
    // GET /api/profesiones?buscar=medic&estado=1&requiere_colegiatura=1
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $profesiones = Profesion::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('ProfesionNombre', 'like', "%{$buscar}%")
                    ->orWhere('ProfesionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('ProfesionEstado', $request->boolean('estado')))
            ->when($request->filled('requiere_colegiatura'), fn ($q) =>
                $q->where('ProfesionRequiereColegiatura', $request->boolean('requiere_colegiatura')))
            ->orderBy('ProfesionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ProfesionResource::collection($profesiones);
    }

    // POST /api/profesiones
    public function store(ProfesionRequest $request): JsonResponse
    {
        $profesion = Profesion::create($request->validated());

        return (new ProfesionResource($profesion->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/profesiones/{profesion}
    public function show(Profesion $profesion)
    {
        return new ProfesionResource($profesion);
    }

    // PUT|PATCH /api/profesiones/{profesion}
    public function update(ProfesionRequest $request, Profesion $profesion)
    {
        $profesion->update($request->validated());

        return new ProfesionResource($profesion->fresh());
    }

    // DELETE /api/profesiones/{profesion}
    public function destroy(Profesion $profesion): JsonResponse
    {
        // BAJA LOGICA: Personal.Trabajador y Personal.ColegiaturaTipo referencian ProfesionId.
        $profesion->update(['ProfesionEstado' => 0]);

        return response()->json(['mensaje' => 'Profesión desactivada.'], 200);
    }
}
