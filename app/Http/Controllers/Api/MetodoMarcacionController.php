<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetodoMarcacionRequest;
use App\Http\Resources\MetodoMarcacionResource;
use App\Models\Biometria\MetodoMarcacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetodoMarcacionController extends Controller
{
    // GET /api/metodos-marcacion?buscar=huella&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $metodos = MetodoMarcacion::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('MetodoMarcacionNombre', 'like', "%{$buscar}%")
                    ->orWhere('MetodoMarcacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('MetodoMarcacionEstado', $request->boolean('estado')))
            ->orderBy('MetodoMarcacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return MetodoMarcacionResource::collection($metodos);
    }

    // POST /api/metodos-marcacion
    public function store(MetodoMarcacionRequest $request): JsonResponse
    {
        $metodo = MetodoMarcacion::create($request->validated());

        return (new MetodoMarcacionResource($metodo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/metodos-marcacion/{metodo}
    public function show(MetodoMarcacion $metodo)
    {
        return new MetodoMarcacionResource($metodo);
    }

    // PUT|PATCH /api/metodos-marcacion/{metodo}
    public function update(MetodoMarcacionRequest $request, MetodoMarcacion $metodo)
    {
        $metodo->update($request->validated());

        return new MetodoMarcacionResource($metodo->fresh());
    }

    // DELETE /api/metodos-marcacion/{metodo}
    public function destroy(MetodoMarcacion $metodo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: es un catalogo base del modulo de Biometria.
        $metodo->update(['MetodoMarcacionEstado' => 0]);

        return response()->json(['mensaje' => 'Método de marcación desactivado.'], 200);
    }
}
