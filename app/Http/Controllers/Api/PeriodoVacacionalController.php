<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodoVacacionalRequest;
use App\Http\Resources\PeriodoVacacionalResource;
use App\Models\Vacaciones\PeriodoVacacional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodoVacacionalController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    // GET /api/periodos-vacacionales?buscar=...&vinculo_laboral_id=&anio=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = PeriodoVacacional::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('anio'), fn ($q) => $q->where('PeriodoVacacionalAnio', $request->integer('anio')))
            ->when($request->filled('estado'), fn ($q) => $q->where('PeriodoVacacionalEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))

            ->orderByDesc('PeriodoVacacionalAnio')
            ->orderByDesc('PeriodoVacacionalId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return PeriodoVacacionalResource::collection($registros);
    }

    // POST /api/periodos-vacacionales
    public function store(PeriodoVacacionalRequest $request): JsonResponse
    {
        $periodo = PeriodoVacacional::create($request->datos());

        return (new PeriodoVacacionalResource($periodo->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/periodos-vacacionales/{periodo}
    public function show(PeriodoVacacional $periodo)
    {
        return new PeriodoVacacionalResource($periodo->load(self::RELACIONES));
    }

    // PUT|PATCH /api/periodos-vacacionales/{periodo}
    public function update(PeriodoVacacionalRequest $request, PeriodoVacacional $periodo)
    {
        $periodo->update($request->datos());

        return new PeriodoVacacionalResource($periodo->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/periodos-vacacionales/{periodo}
    public function destroy(PeriodoVacacional $periodo): JsonResponse
    {
        // El estado es un ciclo de vida: eliminar = pasar a ANULADO. El registro queda como constancia.
        $periodo->update(['PeriodoVacacionalEstado' => 'ANULADO']);

        return response()->json(['mensaje' => 'Período vacacional anulado.'], 200);
    }
}
