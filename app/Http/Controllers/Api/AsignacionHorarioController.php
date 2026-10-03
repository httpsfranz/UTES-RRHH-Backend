<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsignacionHorarioRequest;
use App\Http\Resources\AsignacionHorarioResource;
use App\Models\Personal\AsignacionHorario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsignacionHorarioController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'horario:HorarioId,HorarioCodigo,HorarioNombre',
    ];

    // GET /api/asignaciones-horario?buscar=...&vinculo_laboral_id=&horario_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = AsignacionHorario::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('AsignacionHorarioObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('horario_id'), fn ($q) => $q->where('HorarioId', $request->integer('horario_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('AsignacionHorarioEstado', $request->boolean('estado')))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('vigente'), fn ($q) => $request->boolean('vigente')
                ? $q->where('AsignacionHorarioEstado', 1)->whereDate('AsignacionHorarioFechaInicio', '<=', now()->toDateString())->where(fn ($s) => $s->whereNull('AsignacionHorarioFechaFin')->orWhereDate('AsignacionHorarioFechaFin', '>=', now()->toDateString()))
                : $q->where(fn ($s) => $s->where('AsignacionHorarioEstado', 0)->orWhereDate('AsignacionHorarioFechaInicio', '>', now()->toDateString())->orWhereDate('AsignacionHorarioFechaFin', '<', now()->toDateString())))

            ->orderByDesc('AsignacionHorarioFechaInicio')
            ->orderByDesc('AsignacionHorarioId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return AsignacionHorarioResource::collection($registros);
    }

    // POST /api/asignaciones-horario
    public function store(AsignacionHorarioRequest $request): JsonResponse
    {
        $asignacion = AsignacionHorario::create($request->datos());

        return (new AsignacionHorarioResource($asignacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/asignaciones-horario/{asignacion}
    public function show(AsignacionHorario $asignacion)
    {
        return new AsignacionHorarioResource($asignacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/asignaciones-horario/{asignacion}
    public function update(AsignacionHorarioRequest $request, AsignacionHorario $asignacion)
    {
        $asignacion->update($request->datos());

        return new AsignacionHorarioResource($asignacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/asignaciones-horario/{asignacion}
    public function destroy(AsignacionHorario $asignacion): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial se conserva.
        $asignacion->update(['AsignacionHorarioEstado' => false]);

        return response()->json(['mensaje' => 'Asignación de horario desactivada.'], 200);
    }
}
