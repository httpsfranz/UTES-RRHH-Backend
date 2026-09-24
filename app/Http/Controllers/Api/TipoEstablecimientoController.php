<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoEstablecimientoRequest;
use App\Http\Resources\TipoEstablecimientoResource;
use App\Models\Organizacion\TipoEstablecimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoEstablecimientoController extends Controller
{
    // GET /api/tipos-establecimiento?buscar=centro&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoEstablecimiento::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoEstablecimientoNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoEstablecimientoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoEstablecimientoEstado', $request->boolean('estado')))
            ->orderBy('TipoEstablecimientoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoEstablecimientoResource::collection($tipos);
    }

    // POST /api/tipos-establecimiento
    public function store(TipoEstablecimientoRequest $request): JsonResponse
    {
        $tipo = TipoEstablecimiento::create($request->validated());

        return (new TipoEstablecimientoResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-establecimiento/{tipo}
    public function show(TipoEstablecimiento $tipo)
    {
        return new TipoEstablecimientoResource($tipo);
    }

    // PUT|PATCH /api/tipos-establecimiento/{tipo}
    public function update(TipoEstablecimientoRequest $request, TipoEstablecimiento $tipo)
    {
        $tipo->update($request->validated());

        return new TipoEstablecimientoResource($tipo->fresh());
    }

    // DELETE /api/tipos-establecimiento/{tipo}
    public function destroy(TipoEstablecimiento $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Organizacion.EstablecimientoSalud tiene FK hacia esta tabla.
        $tipo->update(['TipoEstablecimientoEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de establecimiento desactivado.'], 200);
    }
}
