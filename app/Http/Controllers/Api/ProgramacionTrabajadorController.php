<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgramacionTrabajadorRequest;
use App\Http\Resources\ProgramacionTrabajadorResource;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Services\ProgramacionTrabajadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramacionTrabajadorController extends Controller
{
    private const RELACIONES = [
        'periodo:ProgramacionPeriodoId,EessId,ProgramacionPeriodoCodigo,ProgramacionPeriodoFechaInicio,ProgramacionPeriodoFechaFin,ProgramacionPeriodoEstado',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    // GET /api/programaciones-trabajador?buscar=...&programacion_periodo_id=&vinculo_laboral_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ProgramacionTrabajador::query()
            ->with(self::RELACIONES)
            ->withCount(['turnos'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ProgramacionTrabajadorObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('programacion_periodo_id'), fn ($q) => $q->where('ProgramacionPeriodoId', $request->integer('programacion_periodo_id')))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ProgramacionTrabajadorEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('periodo', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))

            ->orderByDesc('ProgramacionPeriodoId')
            ->orderBy('ProgramacionTrabajadorId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ProgramacionTrabajadorResource::collection($registros);
    }

    // POST /api/programaciones-trabajador
    public function store(ProgramacionTrabajadorRequest $request): JsonResponse
    {
        $programacionTrabajador = ProgramacionTrabajador::create($request->datos());

        return (new ProgramacionTrabajadorResource($programacionTrabajador->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/programaciones-trabajador/{programacionTrabajador}
    public function show(ProgramacionTrabajador $programacionTrabajador)
    {
        return new ProgramacionTrabajadorResource($programacionTrabajador->load(self::RELACIONES)->loadCount(['turnos']));
    }

    // PUT|PATCH /api/programaciones-trabajador/{programacionTrabajador}
    public function update(ProgramacionTrabajadorRequest $request, ProgramacionTrabajador $programacionTrabajador)
    {
        $programacionTrabajador->update($request->datos());

        return new ProgramacionTrabajadorResource($programacionTrabajador->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/programaciones-trabajador/{programacionTrabajador}
    public function destroy(ProgramacionTrabajador $programacionTrabajador, ProgramacionTrabajadorService $servicio): JsonResponse
    {
        $servicio->eliminar($programacionTrabajador);

        return response()->json(['mensaje' => 'Trabajador retirado de la programación.'], 200);
    }
}
