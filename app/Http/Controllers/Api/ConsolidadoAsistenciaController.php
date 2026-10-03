<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsolidadoAsistenciaRequest;
use App\Http\Requests\ConsolidadoGenerarRequest;
use App\Http\Resources\ConsolidadoAsistenciaResource;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Services\ConsolidadoAsistenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsolidadoAsistenciaController extends Controller
{
    private const RELACIONES = [
        'periodo:PeriodoAsistenciaId,PeriodoAsistenciaAnio,PeriodoAsistenciaMes,PeriodoAsistenciaEstado',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    // GET /api/consolidados-asistencia?buscar=...&periodo_asistencia_id=&vinculo_laboral_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ConsolidadoAsistencia::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('periodo_asistencia_id'), fn ($q) => $q->where('PeriodoAsistenciaId', $request->integer('periodo_asistencia_id')))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ConsolidadoAsistenciaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))

            ->orderByDesc('PeriodoAsistenciaId')
            ->orderBy('VinculoLaboralId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ConsolidadoAsistenciaResource::collection($registros);
    }

    // POST /api/consolidados-asistencia
    public function store(ConsolidadoAsistenciaRequest $request): JsonResponse
    {
        $consolidado = ConsolidadoAsistencia::create($request->datos());

        return (new ConsolidadoAsistenciaResource($consolidado->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/consolidados-asistencia/{consolidado}
    public function show(ConsolidadoAsistencia $consolidado)
    {
        return new ConsolidadoAsistenciaResource($consolidado->load(self::RELACIONES));
    }

    // PUT|PATCH /api/consolidados-asistencia/{consolidado}
    public function update(ConsolidadoAsistenciaRequest $request, ConsolidadoAsistencia $consolidado)
    {
        $consolidado->update($request->datos());

        return new ConsolidadoAsistenciaResource($consolidado->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/consolidados-asistencia/{consolidado}
    public function destroy(ConsolidadoAsistencia $consolidado, ConsolidadoAsistenciaService $servicio): JsonResponse
    {
        $servicio->eliminar($consolidado);

        return response()->json(['mensaje' => 'Consolidado eliminado.'], 200);
    }

    // POST /api/consolidados-asistencia/generar   { PeriodoAsistenciaId, VinculoLaboralId?, EessId? }
    // Calcula los consolidados del periodo a partir de la asistencia diaria (no toca los CONFORME ni CERRADO).
    public function generar(ConsolidadoGenerarRequest $request, ConsolidadoAsistenciaService $servicio): JsonResponse
    {
        $periodo = PeriodoAsistencia::query()->findOrFail($request->integer('PeriodoAsistenciaId'));
        $resumen = $servicio->generar($periodo, $request->filled('VinculoLaboralId') ? $request->integer('VinculoLaboralId') : null, $request->filled('EessId') ? $request->integer('EessId') : null);

        return response()->json(['mensaje' => 'Consolidado generado.'] + $resumen, 200);
    }
}
