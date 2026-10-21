<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsolidadoAsistenciaRequest;
use App\Http\Requests\ConsolidadoGenerarRequest;
use App\Http\Resources\ConsolidadoAsistenciaResource;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Services\ConsolidadoAsistenciaService;
use App\Services\RevisionConsolidadoService;
use App\Support\AlcanceDeEess;
use App\Support\RegistroAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConsolidadoAsistenciaController extends Controller
{
    private const RELACIONES = [
        'periodo:PeriodoAsistenciaId,PeriodoAsistenciaAnio,PeriodoAsistenciaMes,PeriodoAsistenciaEstado',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'vinculoLaboral.eess:EessId,EessNombre',
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
            ->when(AlcanceDeEess::restringeVinculos($request), fn ($q) => $q->whereIn('VinculoLaboralId', AlcanceDeEess::vinculos($request)))
            ->when($request->filled('estado'), fn ($q) => $q->where('ConsolidadoAsistenciaEstado', $request->string('estado')->toString()))
            ->when(AlcanceDeEess::restringe($request), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->whereIn('EessId', AlcanceDeEess::ids($request))))

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

    // GET /api/consolidados-asistencia/{consolidado}/incidencias   dias con falta, tardanza, omision o salida anticipada
    public function incidencias(ConsolidadoAsistencia $consolidado, RevisionConsolidadoService $servicio): JsonResponse
    {
        $this->exigirAlcance($consolidado);

        return response()->json(['data' => $servicio->incidencias($consolidado->load('periodo'))]);
    }

    // POST /api/consolidados-asistencia/{consolidado}/observar   { Observacion }   pide corregir la asistencia de origen
    public function observar(Request $request, ConsolidadoAsistencia $consolidado, RevisionConsolidadoService $servicio): JsonResponse
    {
        $this->exigirAlcance($consolidado);
        $datos = $request->validate(['Observacion' => ['required', 'string', 'min:5', 'max:1000']], [], ['Observacion' => 'observación']);

        return (new ConsolidadoAsistenciaResource($servicio->observar($consolidado, $request->user(), $datos['Observacion'])->load(self::RELACIONES)))->response();
    }

    // POST /api/consolidados-asistencia/{consolidado}/aprobar   { Observacion? }   conformidad del revisor
    public function aprobar(Request $request, ConsolidadoAsistencia $consolidado, RevisionConsolidadoService $servicio): JsonResponse
    {
        $this->exigirAlcance($consolidado);
        $datos = $request->validate(['Observacion' => ['nullable', 'string', 'max:1000']]);

        return (new ConsolidadoAsistenciaResource($servicio->aprobar($consolidado, $request->user(), $datos['Observacion'] ?? null)->load(self::RELACIONES)))->response();
    }

    // GET /api/consolidados-asistencia/exportar?periodo_asistencia_id=&eess_id=   Excel con una hoja por condicion laboral
    public function exportar(Request $request, RevisionConsolidadoService $servicio): BinaryFileResponse
    {
        $request->validate(['periodo_asistencia_id' => ['required', 'integer'], 'eess_id' => ['nullable', 'integer']]);
        $periodo = PeriodoAsistencia::query()->findOrFail($request->integer('periodo_asistencia_id'));
        $eess = AlcanceDeEess::restringe($request) ? AlcanceDeEess::ids($request) : null;
        RegistroAuditoria::evento('EXPORTAR', 'Consolidacion', 'ConsolidadoAsistencia', (string) $periodo->PeriodoAsistenciaId, 'Exportó el consolidado '.$periodo->PeriodoAsistenciaMes.'/'.$periodo->PeriodoAsistenciaAnio.' a Excel.');

        $nombre = sprintf('Consolidado_%d-%02d%s.xlsx', $periodo->PeriodoAsistenciaAnio, $periodo->PeriodoAsistenciaMes, $request->filled('eess_id') ? '_EESS'.$request->integer('eess_id') : '');

        return response()->download($servicio->exportar($periodo, $eess), $nombre)->deleteFileAfterSend();
    }

    private function exigirAlcance(ConsolidadoAsistencia $consolidado): void
    {
        $eess = (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $consolidado->VinculoLaboralId)->value('EessId');
        AlcanceDeEess::exigir($eess, request()->user());
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
