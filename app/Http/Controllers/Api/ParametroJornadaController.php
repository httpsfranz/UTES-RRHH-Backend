<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParametroJornadaRequest;
use App\Http\Resources\ParametroJornadaResource;
use App\Models\Configuracion\ParametroJornada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametroJornadaController extends Controller
{
    // GET /api/parametros-jornada?tipo_jornada_id=1&vigente_en=2026-09-30&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $parametros = ParametroJornada::query()
            ->with('tipoJornada:TipoJornadaId,TipoJornadaNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->whereHas('tipoJornada', fn ($t) => $t
                ->where('TipoJornadaNombre', 'like', '%'.$request->string('buscar')->toString().'%')))
            ->when($request->filled('tipo_jornada_id'), fn ($q) => $q->where('TipoJornadaId', $request->integer('tipo_jornada_id')))
            // Los parametros que rigen en una fecha dada.
            ->when($request->filled('vigente_en'), fn ($q) => $q
                ->whereDate('ParametroJornadaVigenciaDesde', '<=', $request->date('vigente_en'))
                ->where(fn ($s) => $s->whereNull('ParametroJornadaVigenciaHasta')
                    ->orWhereDate('ParametroJornadaVigenciaHasta', '>=', $request->date('vigente_en'))))
            ->when($request->filled('estado'), fn ($q) => $q->where('ParametroJornadaEstado', $request->boolean('estado')))
            ->orderBy('TipoJornadaId')
            ->orderByDesc('ParametroJornadaVigenciaDesde')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ParametroJornadaResource::collection($parametros);
    }

    // POST /api/parametros-jornada
    public function store(ParametroJornadaRequest $request): JsonResponse
    {
        $parametro = ParametroJornada::create($request->validated());

        return (new ParametroJornadaResource($parametro->fresh()->load('tipoJornada')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/parametros-jornada/{parametro}
    public function show(ParametroJornada $parametro)
    {
        return new ParametroJornadaResource($parametro->load('tipoJornada'));
    }

    // PUT|PATCH /api/parametros-jornada/{parametro}
    public function update(ParametroJornadaRequest $request, ParametroJornada $parametro)
    {
        $parametro->update($request->validated());

        return new ParametroJornadaResource($parametro->fresh()->load('tipoJornada'));
    }

    // DELETE /api/parametros-jornada/{parametro}
    public function destroy(ParametroJornada $parametro): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: es historia (que jornada regia en cada periodo).
        $parametro->update(['ParametroJornadaEstado' => false]);

        return response()->json(['mensaje' => 'Parámetro de jornada desactivado.'], 200);
    }
}
