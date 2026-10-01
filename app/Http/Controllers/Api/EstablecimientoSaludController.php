<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstablecimientoSaludRequest;
use App\Http\Resources\EstablecimientoSaludResource;
use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablecimientoSaludController extends Controller
{
    // GET /api/establecimientos?buscar=esperanza&microred_id=1&tipo_establecimiento_id=1&categoria=I-4&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $establecimientos = EstablecimientoSalud::query()
            ->with(['microred:MicroredId,MicroredNombre', 'tipoEstablecimiento:TipoEstablecimientoId,TipoEstablecimientoNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('EessNombre', 'like', "%{$buscar}%")
                ->orWhere('EessCodigo', 'like', "%{$buscar}%")
                ->orWhere('EessCodigoRenipres', 'like', "%{$buscar}%")))
            ->when($request->filled('microred_id'), fn ($q) => $q->where('MicroredId', $request->integer('microred_id')))
            ->when($request->filled('tipo_establecimiento_id'), fn ($q) => $q->where('TipoEstablecimientoId', $request->integer('tipo_establecimiento_id')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('EessCategoria', $request->string('categoria')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('EessEstado', $request->boolean('estado')))
            ->orderBy('EessNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return EstablecimientoSaludResource::collection($establecimientos);
    }

    // POST /api/establecimientos
    public function store(EstablecimientoSaludRequest $request): JsonResponse
    {
        $establecimiento = EstablecimientoSalud::create($request->validated());

        return (new EstablecimientoSaludResource($establecimiento->fresh()->load('microred', 'tipoEstablecimiento')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/establecimientos/{establecimiento}
    public function show(EstablecimientoSalud $establecimiento)
    {
        return new EstablecimientoSaludResource($establecimiento->load('microred', 'tipoEstablecimiento'));
    }

    // PUT|PATCH /api/establecimientos/{establecimiento}
    public function update(EstablecimientoSaludRequest $request, EstablecimientoSalud $establecimiento)
    {
        $establecimiento->update($request->validated());

        return new EstablecimientoSaludResource($establecimiento->fresh()->load('microred', 'tipoEstablecimiento'));
    }

    // DELETE /api/establecimientos/{establecimiento}
    public function destroy(EstablecimientoSalud $establecimiento): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: hay FK que dependen de esta fila (vinculos laborales, horarios, dispositivos...).
        $establecimiento->update(['EessEstado' => false]);

        return response()->json(['mensaje' => 'Establecimiento de salud desactivado.'], 200);
    }
}
