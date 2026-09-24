<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoCambioTurnoRequest;
use App\Http\Resources\TipoCambioTurnoResource;
use App\Models\Programacion\TipoCambioTurno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoCambioTurnoController extends Controller
{
    // GET /api/tipos-cambio-turno?buscar=permuta&estado=1&requiere_reemplazante=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoCambioTurno::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoCambioTurnoNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoCambioTurnoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoCambioTurnoEstado', $request->boolean('estado')))
            ->when($request->filled('requiere_reemplazante'), fn ($q) =>
                $q->where('TipoCambioTurnoRequiereReemplazante', $request->boolean('requiere_reemplazante')))
            ->orderBy('TipoCambioTurnoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoCambioTurnoResource::collection($tipos);
    }

    // POST /api/tipos-cambio-turno
    public function store(TipoCambioTurnoRequest $request): JsonResponse
    {
        $tipo = TipoCambioTurno::create($request->validated());

        return (new TipoCambioTurnoResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-cambio-turno/{tipo}
    public function show(TipoCambioTurno $tipo)
    {
        return new TipoCambioTurnoResource($tipo);
    }

    // PUT|PATCH /api/tipos-cambio-turno/{tipo}
    public function update(TipoCambioTurnoRequest $request, TipoCambioTurno $tipo)
    {
        $tipo->update($request->validated());

        return new TipoCambioTurnoResource($tipo->fresh());
    }

    // DELETE /api/tipos-cambio-turno/{tipo}
    public function destroy(TipoCambioTurno $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Programacion.CambioTurno tiene una FK hacia esta tabla.
        $tipo->update(['TipoCambioTurnoEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de cambio de turno desactivado.'], 200);
    }
}
