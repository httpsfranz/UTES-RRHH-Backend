<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CargoRequest;
use App\Http\Resources\CargoResource;
use App\Models\Personal\Cargo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    // GET /api/cargos?buscar=enfermer&grupo_ocupacional_id=2&es_jefatura=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $cargos = Cargo::query()
            ->with('grupoOcupacional:GrupoOcupacionalId,GrupoOcupacionalNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CargoNombre', 'like', "%{$buscar}%")
                ->orWhere('CargoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('grupo_ocupacional_id'), fn ($q) => $q->where('GrupoOcupacionalId', $request->integer('grupo_ocupacional_id')))
            ->when($request->filled('es_jefatura'), fn ($q) => $q->where('CargoEsJefatura', $request->boolean('es_jefatura')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CargoEstado', $request->boolean('estado')))
            ->orderBy('CargoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CargoResource::collection($cargos);
    }

    // POST /api/cargos
    public function store(CargoRequest $request): JsonResponse
    {
        $cargo = Cargo::create($request->validated());

        return (new CargoResource($cargo->fresh()->load('grupoOcupacional')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/cargos/{cargo}
    public function show(Cargo $cargo)
    {
        return new CargoResource($cargo->load('grupoOcupacional'));
    }

    // PUT|PATCH /api/cargos/{cargo}
    public function update(CargoRequest $request, Cargo $cargo)
    {
        $cargo->update($request->validated());

        return new CargoResource($cargo->fresh()->load('grupoOcupacional'));
    }

    // DELETE /api/cargos/{cargo}
    public function destroy(Cargo $cargo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Personal.VinculoLaboral tiene una FK hacia esta tabla.
        $cargo->update(['CargoEstado' => false]);

        return response()->json(['mensaje' => 'Cargo desactivado.'], 200);
    }
}
