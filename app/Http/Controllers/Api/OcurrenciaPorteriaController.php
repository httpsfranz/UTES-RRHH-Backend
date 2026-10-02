<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OcurrenciaPorteriaRequest;
use App\Http\Resources\OcurrenciaPorteriaResource;
use App\Models\Solicitudes\OcurrenciaPorteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OcurrenciaPorteriaController extends Controller
{
    private const RELACIONES = [
        'eess:EessId,EessCodigo,EessNombre',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'usuario:UsuarioId,UsuarioNombre',
    ];

    // GET /api/ocurrencias-porteria?buscar=papeleta|quispe&eess_id=1&tipo=OTRO&estado=REGISTRADO&desde=2026-09-01&hasta=2026-09-30
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $ocurrencias = OcurrenciaPorteria::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('OcurrenciaPorteriaDescripcion', 'like', "%{$buscar}%")
                ->orWhere('OcurrenciaPorteriaTipo', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('OcurrenciaPorteriaTipo', $request->string('tipo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('OcurrenciaPorteriaEstado', $request->string('estado')->toString()))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('OcurrenciaPorteriaFechaHora', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('OcurrenciaPorteriaFechaHora', '<=', $request->date('hasta')))
            ->orderByDesc('OcurrenciaPorteriaFechaHora')
            ->orderByDesc('OcurrenciaPorteriaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return OcurrenciaPorteriaResource::collection($ocurrencias);
    }

    // POST /api/ocurrencias-porteria
    public function store(OcurrenciaPorteriaRequest $request): JsonResponse
    {
        $ocurrencia = OcurrenciaPorteria::create($request->validated());

        return (new OcurrenciaPorteriaResource($ocurrencia->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/ocurrencias-porteria/{ocurrencia}
    public function show(OcurrenciaPorteria $ocurrencia)
    {
        return new OcurrenciaPorteriaResource($ocurrencia->load(self::RELACIONES));
    }

    // PUT|PATCH /api/ocurrencias-porteria/{ocurrencia}  (p. ej. pasar de REGISTRADO a ATENDIDO)
    public function update(OcurrenciaPorteriaRequest $request, OcurrenciaPorteria $ocurrencia)
    {
        $ocurrencia->update($request->validated());

        return new OcurrenciaPorteriaResource($ocurrencia->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/ocurrencias-porteria/{ocurrencia}
    public function destroy(OcurrenciaPorteria $ocurrencia): JsonResponse
    {
        // El estado es un ciclo de vida: eliminar = ANULAR (definitivo). El registro queda como constancia.
        $ocurrencia->update(['OcurrenciaPorteriaEstado' => 'ANULADO']);

        return response()->json(['mensaje' => 'Ocurrencia anulada.'], 200);
    }
}
