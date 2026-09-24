<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CondicionLaboralRequest;
use App\Http\Resources\CondicionLaboralResource;
use App\Models\Personal\CondicionLaboral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CondicionLaboralController extends Controller
{
    // GET /api/condiciones-laborales?buscar=nombrado&estado=1&es_permanente=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $condiciones = CondicionLaboral::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('CondicionLaboralNombre', 'like', "%{$buscar}%")
                    ->orWhere('CondicionLaboralCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('CondicionLaboralEstado', $request->boolean('estado')))
            ->when($request->filled('es_permanente'), fn ($q) =>
                $q->where('CondicionLaboralEsPermanente', $request->boolean('es_permanente')))
            ->orderBy('CondicionLaboralNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CondicionLaboralResource::collection($condiciones);
    }

    // POST /api/condiciones-laborales
    public function store(CondicionLaboralRequest $request): JsonResponse
    {
        $condicion = CondicionLaboral::create($request->validated());

        return (new CondicionLaboralResource($condicion->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/condiciones-laborales/{condicion}
    public function show(CondicionLaboral $condicion)
    {
        return new CondicionLaboralResource($condicion);
    }

    // PUT|PATCH /api/condiciones-laborales/{condicion}
    public function update(CondicionLaboralRequest $request, CondicionLaboral $condicion)
    {
        $condicion->update($request->validated());

        return new CondicionLaboralResource($condicion->fresh());
    }

    // DELETE /api/condiciones-laborales/{condicion}
    public function destroy(CondicionLaboral $condicion): JsonResponse
    {
        // BAJA LOGICA: Personal.VinculoLaboral referencia este catalogo.
        $condicion->update(['CondicionLaboralEstado' => 0]);

        return response()->json(['mensaje' => 'Condición laboral desactivada.'], 200);
    }
}
