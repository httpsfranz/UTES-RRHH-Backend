<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoPeriodoProgramacionRequest;
use App\Http\Resources\TipoPeriodoProgramacionResource;
use App\Models\Programacion\TipoPeriodoProgramacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoPeriodoProgramacionController extends Controller
{
    // GET /api/tipos-periodo-programacion?buscar=mensual&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoPeriodoProgramacion::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoPeriodoProgramacionNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoPeriodoProgramacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoPeriodoProgramacionEstado', $request->boolean('estado')))
            ->orderBy('TipoPeriodoProgramacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoPeriodoProgramacionResource::collection($tipos);
    }

    // POST /api/tipos-periodo-programacion
    public function store(TipoPeriodoProgramacionRequest $request): JsonResponse
    {
        $tipo = TipoPeriodoProgramacion::create($request->validated());

        return (new TipoPeriodoProgramacionResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-periodo-programacion/{tipo}
    public function show(TipoPeriodoProgramacion $tipo)
    {
        return new TipoPeriodoProgramacionResource($tipo);
    }

    // PUT|PATCH /api/tipos-periodo-programacion/{tipo}
    public function update(TipoPeriodoProgramacionRequest $request, TipoPeriodoProgramacion $tipo)
    {
        $tipo->update($request->validated());

        return new TipoPeriodoProgramacionResource($tipo->fresh());
    }

    // DELETE /api/tipos-periodo-programacion/{tipo}
    public function destroy(TipoPeriodoProgramacion $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Programacion.ProgramacionPeriodo tiene una FK hacia esta tabla.
        $tipo->update(['TipoPeriodoProgramacionEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de periodo de programación desactivado.'], 200);
    }
}
