<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LiquidacionDescuentoRequest;
use App\Http\Resources\LiquidacionDescuentoResource;
use App\Models\Compensaciones\LiquidacionDescuento;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiquidacionDescuentoController extends Controller
{
    private const RELACIONES = [
        'consolidado:ConsolidadoAsistenciaId,PeriodoAsistenciaId,VinculoLaboralId,ConsolidadoAsistenciaEstado,ConsolidadoAsistenciaDiasFalta,ConsolidadoAsistenciaMinutosTardanza',
        'consolidado.periodo:PeriodoAsistenciaId,PeriodoAsistenciaAnio,PeriodoAsistenciaMes',
        'consolidado.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'consolidado.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    /** Al ver una liquidacion se devuelven tambien sus lineas. */
    private const RELACIONES_DETALLE = [...self::RELACIONES, 'detalles.concepto:ConceptoDescuentoId,ConceptoDescuentoCodigo,ConceptoDescuentoNombre'];

    // GET /api/liquidaciones-descuento?buscar=...&consolidado_asistencia_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = LiquidacionDescuento::query()
            ->with(self::RELACIONES)
            ->withCount(['detalles'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('consolidado.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('consolidado_asistencia_id'), fn ($q) => $q->where('ConsolidadoAsistenciaId', $request->integer('consolidado_asistencia_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('LiquidacionDescuentoEstado', $request->string('estado')->toString()))
            ->when($request->filled('periodo_asistencia_id'), fn ($q) => $q->whereHas('consolidado', fn ($c) => $c->where('PeriodoAsistenciaId', $request->integer('periodo_asistencia_id'))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('consolidado', fn ($c) => $c->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('consolidado.vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('LiquidacionDescuentoFechaGeneracion', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('LiquidacionDescuentoFechaGeneracion', '<=', $request->date('hasta')))
            ->orderByDesc('LiquidacionDescuentoFechaGeneracion')
            ->orderByDesc('LiquidacionDescuentoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return LiquidacionDescuentoResource::collection($registros);
    }

    // POST /api/liquidaciones-descuento   { ConsolidadoAsistenciaId }
    // Genera la liquidacion con las lineas que salen del consolidado (dias de falta y horas de tardanza), con importe 0.
    public function store(LiquidacionDescuentoRequest $request, LiquidacionDescuentoService $servicio): JsonResponse
    {
        $consolidado = ConsolidadoAsistencia::query()->findOrFail($request->integer('ConsolidadoAsistenciaId'));

        return (new LiquidacionDescuentoResource($servicio->generar($consolidado)->load(self::RELACIONES_DETALLE)->loadCount('detalles')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/liquidaciones-descuento/{liquidacion}
    public function show(LiquidacionDescuento $liquidacion)
    {
        return new LiquidacionDescuentoResource($liquidacion->load(self::RELACIONES_DETALLE)->loadCount('detalles'));
    }

    // DELETE /api/liquidaciones-descuento/{liquidacion}   (anula; una liquidacion remitida a planilla no se anula)
    public function destroy(LiquidacionDescuento $liquidacion, LiquidacionDescuentoService $servicio): JsonResponse
    {
        $servicio->anular($liquidacion);

        return response()->json(['mensaje' => 'Liquidación anulada.'], 200);
    }

    // POST /api/liquidaciones-descuento/{liquidacion}/aprobar   (GENERADO -> APROBADO; cada linea necesita su importe)
    public function aprobar(LiquidacionDescuento $liquidacion, LiquidacionDescuentoService $servicio): JsonResponse
    {
        return (new LiquidacionDescuentoResource($servicio->aprobar($liquidacion)->load(self::RELACIONES_DETALLE)->loadCount('detalles')))->response();
    }

    // POST /api/liquidaciones-descuento/{liquidacion}/remitir   (APROBADO -> REMITIDO a la planilla unica de pagos)
    public function remitir(LiquidacionDescuento $liquidacion, LiquidacionDescuentoService $servicio): JsonResponse
    {
        return (new LiquidacionDescuentoResource($servicio->remitir($liquidacion)->load(self::RELACIONES_DETALLE)->loadCount('detalles')))->response();
    }
}
