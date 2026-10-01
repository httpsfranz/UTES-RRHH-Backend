<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TurnoRequest;
use App\Http\Resources\TurnoResource;
use App\Models\Configuracion\Turno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnoController extends Controller
{
    // GET /api/turnos?buscar=manana&tipo_jornada_id=2&es_guardia=0&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $turnos = Turno::query()
            ->with(['tipoJornada:TipoJornadaId,TipoJornadaNombre', 'tablaTolerancia:TablaToleranciaId,TablaToleranciaNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('TurnoNombre', 'like', "%{$buscar}%")
                ->orWhere('TurnoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('tipo_jornada_id'), fn ($q) => $q->where('TipoJornadaId', $request->integer('tipo_jornada_id')))
            ->when($request->filled('es_guardia'), fn ($q) => $q->where('TurnoEsGuardia', $request->boolean('es_guardia')))
            ->when($request->filled('estado'), fn ($q) => $q->where('TurnoEstado', $request->boolean('estado')))
            ->orderBy('TurnoHoraEntrada')
            ->orderBy('TurnoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TurnoResource::collection($turnos);
    }

    // POST /api/turnos
    public function store(TurnoRequest $request): JsonResponse
    {
        $turno = Turno::create($request->validated());

        // fresh(): trae las columnas calculadas (cruza medianoche, duracion).
        return (new TurnoResource($turno->fresh()->load('tipoJornada', 'tablaTolerancia')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/turnos/{turno}
    public function show(Turno $turno)
    {
        return new TurnoResource($turno->load('tipoJornada', 'tablaTolerancia'));
    }

    // PUT|PATCH /api/turnos/{turno}
    public function update(TurnoRequest $request, Turno $turno)
    {
        $turno->update($request->validated());

        return new TurnoResource($turno->fresh()->load('tipoJornada', 'tablaTolerancia'));
    }

    // DELETE /api/turnos/{turno}
    public function destroy(Turno $turno): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: HorarioDetalle y TurnoProgramado referencian el turno.
        $turno->update(['TurnoEstado' => false]);

        return response()->json(['mensaje' => 'Turno desactivado.'], 200);
    }
}
