<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VinculoLaboralRequest;
use App\Http\Resources\VinculoLaboralResource;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VinculoLaboralController extends Controller
{
    private const RELACIONES = ['trabajador', 'eess', 'regimenLaboral', 'condicionLaboral', 'cargo'];

    // GET /api/vinculos-laborales?buscar=quispe|70000001|P-001&trabajador_id=1&eess_id=2&microred_id=1&cargo_id=3
    //     &condicion_laboral_id=1&regimen_laboral_id=1&vigente=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $vinculos = VinculoLaboral::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('VinculoLaboralCodigo', 'like', "%{$buscar}%")
                ->orWhere('VinculoLaboralCodigoAirhsp', 'like', "%{$buscar}%")
                ->orWhere('VinculoLaboralNumeroPlaza', 'like', "%{$buscar}%")
                ->orWhereHas('trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('microred_id'), fn ($q) => $q->whereHas('eess', fn ($e) => $e->where('MicroredId', $request->integer('microred_id'))))
            ->when($request->filled('cargo_id'), fn ($q) => $q->where('CargoId', $request->integer('cargo_id')))
            ->when($request->filled('condicion_laboral_id'), fn ($q) => $q->where('CondicionLaboralId', $request->integer('condicion_laboral_id')))
            ->when($request->filled('regimen_laboral_id'), fn ($q) => $q->where('RegimenLaboralId', $request->integer('regimen_laboral_id')))
            ->when($request->filled('vigente'), fn ($q) => $request->boolean('vigente')
                ? $q->vigentes()
                : $q->where(fn ($s) => $s->where('VinculoLaboralEstado', 0)
                    ->orWhereDate('VinculoLaboralFechaFin', '<', now()->toDateString())
                    ->orWhereDate('VinculoLaboralFechaInicio', '>', now()->toDateString())))
            ->when($request->filled('estado'), fn ($q) => $q->where('VinculoLaboralEstado', $request->boolean('estado')))
            ->orderByDesc('VinculoLaboralFechaInicio')
            ->orderBy('VinculoLaboralId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return VinculoLaboralResource::collection($vinculos);
    }

    // POST /api/vinculos-laborales
    public function store(VinculoLaboralRequest $request): JsonResponse
    {
        $vinculo = VinculoLaboral::create($request->validated());

        return (new VinculoLaboralResource($vinculo->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/vinculos-laborales/{vinculo}
    public function show(VinculoLaboral $vinculo)
    {
        return new VinculoLaboralResource($vinculo->load(self::RELACIONES));
    }

    // PUT|PATCH /api/vinculos-laborales/{vinculo}
    public function update(VinculoLaboralRequest $request, VinculoLaboral $vinculo)
    {
        $vinculo->update($request->validated());

        return new VinculoLaboralResource($vinculo->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/vinculos-laborales/{vinculo}
    public function destroy(VinculoLaboral $vinculo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: marcaciones, asistencia, papeletas y liquidaciones cuelgan del vinculo.
        // Para registrar el cese real (con fecha y motivo) se edita FechaFin y MotivoCese.
        $vinculo->update(['VinculoLaboralEstado' => false]);

        return response()->json(['mensaje' => 'Vínculo laboral desactivado.'], 200);
    }
}
