<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsistenciaDiariaRequest;
use App\Http\Resources\AsistenciaDiariaResource;
use App\Models\Asistencia\AsistenciaDiaria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsistenciaDiariaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'estado:EstadoAsistenciaId,EstadoAsistenciaCodigo,EstadoAsistenciaNombre,EstadoAsistenciaEsFalta,EstadoAsistenciaEsDescontable',
    ];

    // GET /api/asistencia-diaria?buscar=...&vinculo_laboral_id=&estado_asistencia_id=&justificacion_falta_id=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = AsistenciaDiaria::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('AsistenciaDiariaObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('estado_asistencia_id'), fn ($q) => $q->where('EstadoAsistenciaId', $request->integer('estado_asistencia_id')))
            ->when($request->filled('justificacion_falta_id'), fn ($q) => $q->where('JustificacionFaltaId', $request->integer('justificacion_falta_id')))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('sin_justificar'), fn ($q) => $request->boolean('sin_justificar')
                ? $q->whereNull('JustificacionFaltaId')->whereHas('estado', fn ($e) => $e->where('EstadoAsistenciaEsFalta', 1))
                : $q)
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('AsistenciaDiariaFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('AsistenciaDiariaFecha', '<=', $request->date('hasta')))
            ->orderByDesc('AsistenciaDiariaFecha')
            ->orderByDesc('AsistenciaDiariaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return AsistenciaDiariaResource::collection($registros);
    }

    // POST /api/asistencia-diaria
    public function store(AsistenciaDiariaRequest $request): JsonResponse
    {
        $asistencia = AsistenciaDiaria::create($request->datos());

        return (new AsistenciaDiariaResource($asistencia->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/asistencia-diaria/{asistencia}
    public function show(AsistenciaDiaria $asistencia)
    {
        return new AsistenciaDiariaResource($asistencia->load(self::RELACIONES));
    }

    // PUT|PATCH /api/asistencia-diaria/{asistencia}
    public function update(AsistenciaDiariaRequest $request, AsistenciaDiaria $asistencia)
    {
        $asistencia->update($request->datos());

        return new AsistenciaDiariaResource($asistencia->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/asistencia-diaria/{asistencia}
    public function destroy(AsistenciaDiaria $asistencia): JsonResponse
    {
        // DELETE fisico: si otra tabla referencia la fila, ErroresDeBaseDeDatos responde 409.
        $asistencia->delete();

        return response()->json(['mensaje' => 'Asistencia diaria eliminada.'], 200);
    }
}
