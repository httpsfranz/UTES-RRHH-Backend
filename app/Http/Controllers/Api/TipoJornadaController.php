<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoJornadaRequest;
use App\Http\Resources\TipoJornadaResource;
use App\Models\Configuracion\TipoJornada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoJornadaController extends Controller
{
    // GET /api/tipos-jornada?buscar=completa&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoJornada::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoJornadaNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoJornadaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoJornadaEstado', $request->boolean('estado')))
            ->orderBy('TipoJornadaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoJornadaResource::collection($tipos);
    }

    // POST /api/tipos-jornada
    public function store(TipoJornadaRequest $request): JsonResponse
    {
        $tipo = TipoJornada::create($request->validated());

        return (new TipoJornadaResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-jornada/{tipo}
    public function show(TipoJornada $tipo)
    {
        return new TipoJornadaResource($tipo);
    }

    // PUT|PATCH /api/tipos-jornada/{tipo}
    public function update(TipoJornadaRequest $request, TipoJornada $tipo)
    {
        $tipo->update($request->validated());

        return new TipoJornadaResource($tipo->fresh());
    }

    // DELETE /api/tipos-jornada/{tipo}
    public function destroy(TipoJornada $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Configuracion.ParametroJornada y Configuracion.Horario
        // tienen FK hacia esta tabla.
        $tipo->update(['TipoJornadaEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de jornada desactivado.'], 200);
    }
}
