<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegimenLaboralRequest;
use App\Http\Resources\RegimenLaboralResource;
use App\Models\Personal\RegimenLaboral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegimenLaboralController extends Controller
{
    // GET /api/regimenes-laborales?buscar=728&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $regimenes = RegimenLaboral::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('RegimenLaboralNombre', 'like', "%{$buscar}%")
                    ->orWhere('RegimenLaboralCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('RegimenLaboralEstado', $request->boolean('estado')))
            ->orderBy('RegimenLaboralNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return RegimenLaboralResource::collection($regimenes);
    }

    // POST /api/regimenes-laborales
    public function store(RegimenLaboralRequest $request): JsonResponse
    {
        $regimen = RegimenLaboral::create($request->validated());

        return (new RegimenLaboralResource($regimen->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/regimenes-laborales/{regimen}
    public function show(RegimenLaboral $regimen)
    {
        return new RegimenLaboralResource($regimen);
    }

    // PUT|PATCH /api/regimenes-laborales/{regimen}
    public function update(RegimenLaboralRequest $request, RegimenLaboral $regimen)
    {
        $regimen->update($request->validated());

        return new RegimenLaboralResource($regimen->fresh());
    }

    // DELETE /api/regimenes-laborales/{regimen}
    public function destroy(RegimenLaboral $regimen): JsonResponse
    {
        // BAJA LOGICA: Personal.VinculoLaboral referencia este catalogo.
        $regimen->update(['RegimenLaboralEstado' => 0]);

        return response()->json(['mensaje' => 'Régimen laboral desactivado.'], 200);
    }
}
