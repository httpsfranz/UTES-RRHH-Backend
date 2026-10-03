<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TurnoProgramadoRequest;
use App\Http\Resources\TurnoProgramadoResource;
use App\Models\Programacion\TurnoProgramado;
use App\Services\TurnoProgramadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnoProgramadoController extends Controller
{
    private const RELACIONES = [
        'programacionTrabajador:ProgramacionTrabajadorId,ProgramacionPeriodoId,VinculoLaboralId,ProgramacionTrabajadorEstado',
        'programacionTrabajador.periodo:ProgramacionPeriodoId,EessId,ProgramacionPeriodoCodigo,ProgramacionPeriodoFechaInicio,ProgramacionPeriodoFechaFin,ProgramacionPeriodoEstado',
        'programacionTrabajador.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'programacionTrabajador.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'turno:TurnoId,TurnoCodigo,TurnoNombre,TurnoHoraEntrada,TurnoHoraSalida,TurnoDuracionMinutos,TurnoEsGuardia',
    ];

    // GET /api/turnos-programados?buscar=...&periodo_estado=&programacion_trabajador_id=&turno_id=&es_guardia=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = TurnoProgramado::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('TurnoProgramadoObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('programacionTrabajador.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('programacion_trabajador_id'), fn ($q) => $q->where('ProgramacionTrabajadorId', $request->integer('programacion_trabajador_id')))
            ->when($request->filled('turno_id'), fn ($q) => $q->where('TurnoId', $request->integer('turno_id')))
            ->when($request->filled('es_guardia'), fn ($q) => $q->where('TurnoProgramadoEsGuardia', $request->boolean('es_guardia')))
            ->when($request->filled('estado'), fn ($q) => $q->where('TurnoProgramadoEstado', $request->string('estado')->toString()))
            ->when($request->filled('programacion_periodo_id'), fn ($q) => $q->whereHas('programacionTrabajador', fn ($p) => $p->where('ProgramacionPeriodoId', $request->integer('programacion_periodo_id'))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('programacionTrabajador', fn ($p) => $p->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('periodo_estado'), fn ($q) => $q->whereHas('programacionTrabajador.periodo', fn ($p) => $p->where('ProgramacionPeriodoEstado', $request->string('periodo_estado')->toString())))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('programacionTrabajador.periodo', fn ($p) => $p->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('TurnoProgramadoFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('TurnoProgramadoFecha', '<=', $request->date('hasta')))
            ->orderBy('TurnoProgramadoFecha')
            ->orderBy('TurnoProgramadoHoraEntrada')
            ->orderBy('TurnoProgramadoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TurnoProgramadoResource::collection($registros);
    }

    // POST /api/turnos-programados
    public function store(TurnoProgramadoRequest $request): JsonResponse
    {
        $turnoProgramado = TurnoProgramado::create($request->datos());

        return (new TurnoProgramadoResource($turnoProgramado->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/turnos-programados/{turnoProgramado}
    public function show(TurnoProgramado $turnoProgramado)
    {
        return new TurnoProgramadoResource($turnoProgramado->load(self::RELACIONES));
    }

    // PUT|PATCH /api/turnos-programados/{turnoProgramado}
    public function update(TurnoProgramadoRequest $request, TurnoProgramado $turnoProgramado)
    {
        $turnoProgramado->update($request->datos());

        return new TurnoProgramadoResource($turnoProgramado->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/turnos-programados/{turnoProgramado}
    public function destroy(TurnoProgramado $turnoProgramado, TurnoProgramadoService $servicio): JsonResponse
    {
        $servicio->eliminar($turnoProgramado);

        return response()->json(['mensaje' => 'Turno retirado de la programación.'], 200);
    }

    // POST /api/turnos-programados/{turnoProgramado}/cumplir   (el turno ya se realizó: PROGRAMADO|REPROGRAMADO -> CUMPLIDO)
    public function cumplir(TurnoProgramado $turnoProgramado, TurnoProgramadoService $servicio): JsonResponse
    {
        return (new TurnoProgramadoResource($servicio->cumplir($turnoProgramado)->load(self::RELACIONES)))->response();
    }
}
