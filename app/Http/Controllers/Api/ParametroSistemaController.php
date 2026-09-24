<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParametroSistemaRequest;
use App\Http\Resources\ParametroSistemaResource;
use App\Models\Configuracion\ParametroSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametroSistemaController extends Controller
{
    // GET /api/parametros-sistema?buscar=tolerancia&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        // No hay columna "Nombre" en esta tabla: la busqueda es solo por Codigo/Valor.
        $parametros = ParametroSistema::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('ParametroSistemaCodigo', 'like', "%{$buscar}%")
                    ->orWhere('ParametroSistemaValor', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('ParametroSistemaEstado', $request->boolean('estado')))
            ->orderBy('ParametroSistemaCodigo')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ParametroSistemaResource::collection($parametros);
    }

    // POST /api/parametros-sistema
    public function store(ParametroSistemaRequest $request): JsonResponse
    {
        $parametro = ParametroSistema::create($request->validated());

        return (new ParametroSistemaResource($parametro->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/parametros-sistema/{parametro}
    public function show(ParametroSistema $parametro)
    {
        return new ParametroSistemaResource($parametro);
    }

    // PUT|PATCH /api/parametros-sistema/{parametro}
    public function update(ParametroSistemaRequest $request, ParametroSistema $parametro)
    {
        $parametro->update($request->validated());

        return new ParametroSistemaResource($parametro->fresh());
    }

    // DELETE /api/parametros-sistema/{parametro}
    public function destroy(ParametroSistema $parametro): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: mantiene consistencia con el resto de catalogos.
        $parametro->update(['ParametroSistemaEstado' => 0]);

        return response()->json(['mensaje' => 'Parámetro del sistema desactivado.'], 200);
    }
}
