<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DetalleConsolidadoRequest;
use App\Http\Resources\DetalleConsolidadoResource;
use App\Models\Consolidacion\DetalleConsolidado;
use App\Services\DetalleConsolidadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetalleConsolidadoController extends Controller
{
    private const RELACIONES = [
        'consolidado:ConsolidadoAsistenciaId,PeriodoAsistenciaId,VinculoLaboralId,ConsolidadoAsistenciaEstado',
        'consolidado.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'consolidado.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    // GET /api/detalles-consolidado?buscar=...&consolidado_asistencia_id=&asistencia_diaria_id=&estado=&es_justificada=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = DetalleConsolidado::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('DetalleConsolidadoEstado', 'like', "%{$buscar}%")
                ->orWhereHas('consolidado.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('consolidado_asistencia_id'), fn ($q) => $q->where('ConsolidadoAsistenciaId', $request->integer('consolidado_asistencia_id')))
            ->when($request->filled('asistencia_diaria_id'), fn ($q) => $q->where('AsistenciaDiariaId', $request->integer('asistencia_diaria_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('DetalleConsolidadoEstado', $request->string('estado')->toString()))
            ->when($request->filled('es_justificada'), fn ($q) => $q->where('DetalleConsolidadoEsJustificada', $request->boolean('es_justificada')))
            ->when($request->filled('periodo_asistencia_id'), fn ($q) => $q->whereHas('consolidado', fn ($c) => $c->where('PeriodoAsistenciaId', $request->integer('periodo_asistencia_id'))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('consolidado', fn ($c) => $c->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('consolidado.vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('DetalleConsolidadoFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('DetalleConsolidadoFecha', '<=', $request->date('hasta')))
            ->orderByDesc('DetalleConsolidadoFecha')
            ->orderByDesc('DetalleConsolidadoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DetalleConsolidadoResource::collection($registros);
    }

    // POST /api/detalles-consolidado
    public function store(DetalleConsolidadoRequest $request): JsonResponse
    {
        $detalle = DetalleConsolidado::create($request->datos());

        return (new DetalleConsolidadoResource($detalle->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/detalles-consolidado/{detalle}
    public function show(DetalleConsolidado $detalle)
    {
        return new DetalleConsolidadoResource($detalle->load(self::RELACIONES));
    }

    // PUT|PATCH /api/detalles-consolidado/{detalle}
    public function update(DetalleConsolidadoRequest $request, DetalleConsolidado $detalle)
    {
        $detalle->update($request->datos());

        return new DetalleConsolidadoResource($detalle->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/detalles-consolidado/{detalle}
    public function destroy(DetalleConsolidado $detalle, DetalleConsolidadoService $servicio): JsonResponse
    {
        $servicio->eliminar($detalle);

        return response()->json(['mensaje' => 'Detalle eliminado.'], 200);
    }
}
