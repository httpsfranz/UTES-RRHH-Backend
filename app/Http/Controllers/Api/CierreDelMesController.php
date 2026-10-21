<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Services\CierreDelMesService;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cierre del mes: estado del periodo de asistencia paso a paso y las dos acciones en lote que ahorran la revision uno
 * por uno (conformidad de lo que no tiene incidencias y liquidaciones de quien tiene descuentos). Cerrar el periodo
 * sigue siendo PATCH /periodos-asistencia/{id} con PeriodoAsistenciaEstado = CERRADO.
 */
class CierreDelMesController extends Controller
{
    public function __construct(private readonly CierreDelMesService $servicio) {}

    // GET /api/cierre-mes?periodo_asistencia_id=&eess_id=
    public function index(Request $request): JsonResponse
    {
        [$periodo, $eess] = $this->alcance($request, 'periodo_asistencia_id', 'eess_id');

        return response()->json($this->servicio->resumen($periodo, $request->user(), $eess));
    }

    // POST /api/cierre-mes/conformidad   { PeriodoAsistenciaId, EessId? }
    public function conformidad(Request $request): JsonResponse
    {
        [$periodo, $eess] = $this->alcance($request, 'PeriodoAsistenciaId', 'EessId');
        $aprobados = $this->servicio->conformidadSinIncidencias($periodo, $eess, $request->user());

        return response()->json(['aprobados' => $aprobados] + $this->servicio->resumen($periodo, $request->user(), $eess));
    }

    // POST /api/cierre-mes/liquidaciones   { PeriodoAsistenciaId, EessId? }
    public function liquidaciones(Request $request, LiquidacionDescuentoService $liquidaciones): JsonResponse
    {
        [$periodo, $eess] = $this->alcance($request, 'PeriodoAsistenciaId', 'EessId');
        $generadas = $this->servicio->generarLiquidaciones($periodo, $eess, $liquidaciones);

        return response()->json(['generadas' => $generadas] + $this->servicio->resumen($periodo, $request->user(), $eess));
    }

    /** @return array{0:PeriodoAsistencia,1:list<int>|null} */
    private function alcance(Request $request, string $campoPeriodo, string $campoEess): array
    {
        $datos = $request->validate([
            $campoPeriodo => ['required', 'integer', Rule::exists(PeriodoAsistencia::class, 'PeriodoAsistenciaId')],
            $campoEess => ['nullable', 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')],
        ], [], [$campoPeriodo => 'período de asistencia', $campoEess => 'establecimiento']);

        return [
            PeriodoAsistencia::query()->findOrFail($datos[$campoPeriodo]),
            CierreDelMesService::establecimientos(isset($datos[$campoEess]) ? (int) $datos[$campoEess] : null, $request->user()),
        ];
    }
}
