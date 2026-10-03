<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpedientePadRequest;
use App\Http\Resources\ExpedientePadResource;
use App\Models\Disciplina\ExpedientePad;
use App\Services\ExpedientePadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpedientePadController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipoFalta:TipoFaltaDisciplinariaId,TipoFaltaDisciplinariaCodigo,TipoFaltaDisciplinariaNombre,TipoFaltaDisciplinariaGravedad',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/expedientes-pad?buscar=...&vinculo_laboral_id=&tipo_falta_disciplinaria_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ExpedientePad::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ExpedientePadNumero', 'like', "%{$buscar}%")
                ->orWhere('ExpedientePadDescripcion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo_falta_disciplinaria_id'), fn ($q) => $q->where('TipoFaltaDisciplinariaId', $request->integer('tipo_falta_disciplinaria_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ExpedientePadEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('ExpedientePadFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('ExpedientePadFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('ExpedientePadFechaInicio')
            ->orderByDesc('ExpedientePadId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ExpedientePadResource::collection($registros);
    }

    // POST /api/expedientes-pad
    public function store(ExpedientePadRequest $request): JsonResponse
    {
        $expediente = ExpedientePad::create($request->datos());

        return (new ExpedientePadResource($expediente->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/expedientes-pad/{expediente}
    public function show(ExpedientePad $expediente)
    {
        return new ExpedientePadResource($expediente->load(self::RELACIONES));
    }

    // PUT|PATCH /api/expedientes-pad/{expediente}
    public function update(ExpedientePadRequest $request, ExpedientePad $expediente)
    {
        $expediente->update($request->datos());

        return new ExpedientePadResource($expediente->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/expedientes-pad/{expediente}
    public function destroy(ExpedientePad $expediente, ExpedientePadService $servicio): JsonResponse
    {
        $servicio->anular($expediente);

        return response()->json(['mensaje' => 'Expediente anulado.'], 200);
    }
}
