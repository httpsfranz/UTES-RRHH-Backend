<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoLicenciaRequest;
use App\Http\Resources\TipoLicenciaResource;
use App\Models\Solicitudes\TipoLicencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoLicenciaController extends Controller
{
    // GET /api/tipos-licencia?buscar=maternidad&estado=1&con_goce=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoLicencia::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoLicenciaNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoLicenciaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('con_goce'), fn ($q) =>
                $q->where('TipoLicenciaConGoce', $request->boolean('con_goce')))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoLicenciaEstado', $request->boolean('estado')))
            ->orderBy('TipoLicenciaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoLicenciaResource::collection($tipos);
    }

    // POST /api/tipos-licencia
    public function store(TipoLicenciaRequest $request): JsonResponse
    {
        $tipo = TipoLicencia::create($request->validated());

        return (new TipoLicenciaResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-licencia/{tipo}
    public function show(TipoLicencia $tipo)
    {
        return new TipoLicenciaResource($tipo);
    }

    // PUT|PATCH /api/tipos-licencia/{tipo}
    public function update(TipoLicenciaRequest $request, TipoLicencia $tipo)
    {
        $tipo->update($request->validated());

        return new TipoLicenciaResource($tipo->fresh());
    }

    // DELETE /api/tipos-licencia/{tipo}
    public function destroy(TipoLicencia $tipo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Solicitudes.Licencia tiene una FK hacia esta tabla.
        $tipo->update(['TipoLicenciaEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de licencia desactivado.'], 200);
    }
}
