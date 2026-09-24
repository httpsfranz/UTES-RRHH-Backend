<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoCompensacionRequest;
use App\Http\Resources\TipoCompensacionResource;
use App\Models\Compensaciones\TipoCompensacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoCompensacionController extends Controller
{
    // GET /api/tipos-compensacion?buscar=hora&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoCompensacion::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoCompensacionNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoCompensacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoCompensacionEstado', $request->boolean('estado')))
            ->orderBy('TipoCompensacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoCompensacionResource::collection($tipos);
    }

    // POST /api/tipos-compensacion
    public function store(TipoCompensacionRequest $request): JsonResponse
    {
        $tipo = TipoCompensacion::create($request->validated());

        return (new TipoCompensacionResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-compensacion/{tipo}
    public function show(TipoCompensacion $tipo)
    {
        return new TipoCompensacionResource($tipo);
    }

    // PUT|PATCH /api/tipos-compensacion/{tipo}
    public function update(TipoCompensacionRequest $request, TipoCompensacion $tipo)
    {
        $tipo->update($request->validated());

        return new TipoCompensacionResource($tipo->fresh());
    }

    // DELETE /api/tipos-compensacion/{tipo}
    public function destroy(TipoCompensacion $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Compensaciones.CompensacionHoraria tiene FK hacia esta tabla.
        $tipo->update(['TipoCompensacionEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de compensación desactivado.'], 200);
    }
}
