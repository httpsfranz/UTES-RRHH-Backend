<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TablaToleranciaRequest;
use App\Http\Resources\TablaToleranciaResource;
use App\Models\Configuracion\TablaTolerancia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TablaToleranciaController extends Controller
{
    // GET /api/tablas-tolerancia?buscar=estandar&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tablas = TablaTolerancia::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TablaToleranciaNombre', 'like', "%{$buscar}%")
                    ->orWhere('TablaToleranciaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TablaToleranciaEstado', $request->boolean('estado')))
            ->orderBy('TablaToleranciaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TablaToleranciaResource::collection($tablas);
    }

    // POST /api/tablas-tolerancia
    public function store(TablaToleranciaRequest $request): JsonResponse
    {
        $tabla = TablaTolerancia::create($request->validated());

        return (new TablaToleranciaResource($tabla->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tablas-tolerancia/{tabla}
    public function show(TablaTolerancia $tabla)
    {
        return new TablaToleranciaResource($tabla);
    }

    // PUT|PATCH /api/tablas-tolerancia/{tabla}
    public function update(TablaToleranciaRequest $request, TablaTolerancia $tabla)
    {
        $tabla->update($request->validated());

        return new TablaToleranciaResource($tabla->fresh());
    }

    // DELETE /api/tablas-tolerancia/{tabla}
    public function destroy(TablaTolerancia $tabla): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Configuracion.TramoTolerancia y Configuracion.Turno
        // tienen FK hacia esta tabla.
        $tabla->update(['TablaToleranciaEstado' => 0]);

        return response()->json(['mensaje' => 'Tabla de tolerancia desactivada.'], 200);
    }
}
