<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoDocumentoIdentidadRequest;
use App\Http\Resources\TipoDocumentoIdentidadResource;
use App\Models\Personal\TipoDocumentoIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoDocumentoIdentidadController extends Controller
{
    // GET /api/tipos-documento-identidad?buscar=dni&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoDocumentoIdentidad::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoDocumentoIdentidadNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoDocumentoIdentidadCodigo', 'like', "%{$buscar}%")
                    ->orWhere('TipoDocumentoIdentidadAbreviatura', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoDocumentoIdentidadEstado', $request->boolean('estado')))
            ->orderBy('TipoDocumentoIdentidadNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoDocumentoIdentidadResource::collection($tipos);
    }

    // POST /api/tipos-documento-identidad
    public function store(TipoDocumentoIdentidadRequest $request): JsonResponse
    {
        $tipo = TipoDocumentoIdentidad::create($request->validated());

        return (new TipoDocumentoIdentidadResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-documento-identidad/{tipo}
    public function show(TipoDocumentoIdentidad $tipo)
    {
        return new TipoDocumentoIdentidadResource($tipo);
    }

    // PUT|PATCH /api/tipos-documento-identidad/{tipo}
    public function update(TipoDocumentoIdentidadRequest $request, TipoDocumentoIdentidad $tipo)
    {
        $tipo->update($request->validated());

        return new TipoDocumentoIdentidadResource($tipo->fresh());
    }

    // DELETE /api/tipos-documento-identidad/{tipo}
    public function destroy(TipoDocumentoIdentidad $tipo): JsonResponse
    {
        // BAJA LOGICA: Personal.Trabajador referencia este catalogo.
        $tipo->update(['TipoDocumentoIdentidadEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de documento de identidad desactivado.'], 200);
    }
}
