<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DetalleLiquidacionRequest;
use App\Http\Resources\DetalleLiquidacionResource;
use App\Models\Compensaciones\DetalleLiquidacion;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetalleLiquidacionController extends Controller
{
    private const RELACIONES = [
        'liquidacion:LiquidacionDescuentoId,ConsolidadoAsistenciaId,LiquidacionDescuentoEstado',
        'concepto:ConceptoDescuentoId,ConceptoDescuentoCodigo,ConceptoDescuentoNombre',
    ];

    // GET /api/detalles-liquidacion?buscar=...&liquidacion_descuento_id=&concepto_descuento_id=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = DetalleLiquidacion::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('DetalleLiquidacionObservacion', 'like', "%{$buscar}%")))
            ->when($request->filled('liquidacion_descuento_id'), fn ($q) => $q->where('LiquidacionDescuentoId', $request->integer('liquidacion_descuento_id')))
            ->when($request->filled('concepto_descuento_id'), fn ($q) => $q->where('ConceptoDescuentoId', $request->integer('concepto_descuento_id')))

            ->orderByDesc('LiquidacionDescuentoId')
            ->orderBy('DetalleLiquidacionId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DetalleLiquidacionResource::collection($registros);
    }

    // POST /api/detalles-liquidacion
    public function store(DetalleLiquidacionRequest $request): JsonResponse
    {
        $detalle = DetalleLiquidacion::create($request->datos());

        return (new DetalleLiquidacionResource($detalle->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/detalles-liquidacion/{detalle}
    public function show(DetalleLiquidacion $detalle)
    {
        return new DetalleLiquidacionResource($detalle->load(self::RELACIONES));
    }

    // PUT|PATCH /api/detalles-liquidacion/{detalle}
    public function update(DetalleLiquidacionRequest $request, DetalleLiquidacion $detalle)
    {
        $detalle->update($request->datos());

        return new DetalleLiquidacionResource($detalle->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/detalles-liquidacion/{detalle}
    public function destroy(DetalleLiquidacion $detalle, LiquidacionDescuentoService $servicio): JsonResponse
    {
        $servicio->eliminarLinea($detalle);

        return response()->json(['mensaje' => 'Línea eliminada.'], 200);
    }
}
