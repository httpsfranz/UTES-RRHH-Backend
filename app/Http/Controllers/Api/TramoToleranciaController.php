<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TramoToleranciaRequest;
use App\Http\Resources\TramoToleranciaResource;
use App\Models\Configuracion\TramoTolerancia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TramoToleranciaController extends Controller
{
    // GET /api/tramos-tolerancia?tabla_tolerancia_id=1&tipo=TARDANZA&por_pagina=15
    public function index(Request $request)
    {
        $tramos = TramoTolerancia::query()
            ->with('tablaTolerancia:TablaToleranciaId,TablaToleranciaCodigo,TablaToleranciaNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->where('TramoToleranciaDescripcion', 'like', '%'.$request->string('buscar')->toString().'%'))
            ->when($request->filled('tabla_tolerancia_id'), fn ($q) => $q->where('TablaToleranciaId', $request->integer('tabla_tolerancia_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('TramoToleranciaTipo', $request->string('tipo')->toString()))
            ->orderBy('TablaToleranciaId')
            ->orderBy('TramoToleranciaTipo')
            ->orderBy('TramoToleranciaMinutosDesde')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TramoToleranciaResource::collection($tramos);
    }

    // POST /api/tramos-tolerancia
    public function store(TramoToleranciaRequest $request): JsonResponse
    {
        $tramo = TramoTolerancia::create($request->validated());

        return (new TramoToleranciaResource($tramo->fresh()->load('tablaTolerancia')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tramos-tolerancia/{tramo}
    public function show(TramoTolerancia $tramo)
    {
        return new TramoToleranciaResource($tramo->load('tablaTolerancia'));
    }

    // PUT|PATCH /api/tramos-tolerancia/{tramo}
    public function update(TramoToleranciaRequest $request, TramoTolerancia $tramo)
    {
        $tramo->update($request->validated());

        return new TramoToleranciaResource($tramo->fresh()->load('tablaTolerancia'));
    }

    // DELETE /api/tramos-tolerancia/{tramo}
    public function destroy(TramoTolerancia $tramo): JsonResponse
    {
        // Esta tabla no tiene columna de Estado (no hay baja logica posible) y nada la referencia
        // via FK: se elimina fisicamente. Si en el futuro algo la referencia, SQL Server rechazara el
        // DELETE y el manejador global lo devuelve como 409.
        $tramo->delete();

        return response()->json(['mensaje' => 'Tramo de tolerancia eliminado.'], 200);
    }
}
