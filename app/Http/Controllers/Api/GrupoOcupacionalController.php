<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrupoOcupacionalRequest;
use App\Http\Resources\GrupoOcupacionalResource;
use App\Models\Personal\GrupoOcupacional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrupoOcupacionalController extends Controller
{
    // GET /api/grupos-ocupacionales?buscar=asistencial&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $grupos = GrupoOcupacional::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('GrupoOcupacionalNombre', 'like', "%{$buscar}%")
                    ->orWhere('GrupoOcupacionalCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('GrupoOcupacionalEstado', $request->boolean('estado')))
            ->orderBy('GrupoOcupacionalNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return GrupoOcupacionalResource::collection($grupos);
    }

    // POST /api/grupos-ocupacionales
    public function store(GrupoOcupacionalRequest $request): JsonResponse
    {
        $grupo = GrupoOcupacional::create($request->validated());

        return (new GrupoOcupacionalResource($grupo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/grupos-ocupacionales/{grupo}
    public function show(GrupoOcupacional $grupo)
    {
        return new GrupoOcupacionalResource($grupo);
    }

    // PUT|PATCH /api/grupos-ocupacionales/{grupo}
    public function update(GrupoOcupacionalRequest $request, GrupoOcupacional $grupo)
    {
        $grupo->update($request->validated());

        return new GrupoOcupacionalResource($grupo->fresh());
    }

    // DELETE /api/grupos-ocupacionales/{grupo}
    public function destroy(GrupoOcupacional $grupo): JsonResponse
    {
        // BAJA LOGICA: Personal.Cargo referencia este catalogo.
        $grupo->update(['GrupoOcupacionalEstado' => 0]);

        return response()->json(['mensaje' => 'Grupo ocupacional desactivado.'], 200);
    }
}
