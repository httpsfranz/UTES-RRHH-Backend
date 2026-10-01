<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HorarioRequest;
use App\Http\Resources\HorarioResource;
use App\Models\Configuracion\Horario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioController extends Controller
{
    // GET /api/horarios?buscar=administrativo&tipo_jornada_id=1&eess_id=2&es_rotativo=0&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $horarios = Horario::query()
            ->with(['tipoJornada:TipoJornadaId,TipoJornadaNombre', 'eess:EessId,EessCodigo,EessNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('HorarioNombre', 'like', "%{$buscar}%")
                ->orWhere('HorarioCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('tipo_jornada_id'), fn ($q) => $q->where('TipoJornadaId', $request->integer('tipo_jornada_id')))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('es_rotativo'), fn ($q) => $q->where('HorarioEsRotativo', $request->boolean('es_rotativo')))
            ->when($request->filled('estado'), fn ($q) => $q->where('HorarioEstado', $request->boolean('estado')))
            ->orderBy('HorarioNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return HorarioResource::collection($horarios);
    }

    // POST /api/horarios
    public function store(HorarioRequest $request): JsonResponse
    {
        $horario = Horario::create($request->validated());

        return (new HorarioResource($horario->fresh()->load('tipoJornada', 'eess')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/horarios/{horario}
    public function show(Horario $horario)
    {
        return new HorarioResource($horario->load('tipoJornada', 'eess'));
    }

    // PUT|PATCH /api/horarios/{horario}
    public function update(HorarioRequest $request, Horario $horario)
    {
        $horario->update($request->validated());

        return new HorarioResource($horario->fresh()->load('tipoJornada', 'eess'));
    }

    // DELETE /api/horarios/{horario}
    public function destroy(Horario $horario): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: HorarioDetalle y AsignacionHorario referencian el horario.
        $horario->update(['HorarioEstado' => false]);

        return response()->json(['mensaje' => 'Horario desactivado.'], 200);
    }
}
