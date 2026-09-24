<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoResponsabilidadRequest;
use App\Http\Resources\TipoResponsabilidadResource;
use App\Models\Organizacion\TipoResponsabilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoResponsabilidadController extends Controller
{
    // GET /api/tipos-responsabilidad?buscar=jefe&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoResponsabilidad::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoResponsabilidadNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoResponsabilidadCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoResponsabilidadEstado', $request->boolean('estado')))
            ->orderBy('TipoResponsabilidadNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoResponsabilidadResource::collection($tipos);
    }

    // POST /api/tipos-responsabilidad
    public function store(TipoResponsabilidadRequest $request): JsonResponse
    {
        $tipo = TipoResponsabilidad::create($request->validated());

        return (new TipoResponsabilidadResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-responsabilidad/{tipo}
    public function show(TipoResponsabilidad $tipo)
    {
        return new TipoResponsabilidadResource($tipo);
    }

    // PUT|PATCH /api/tipos-responsabilidad/{tipo}
    public function update(TipoResponsabilidadRequest $request, TipoResponsabilidad $tipo)
    {
        $tipo->update($request->validated());

        return new TipoResponsabilidadResource($tipo->fresh());
    }

    // DELETE /api/tipos-responsabilidad/{tipo}
    public function destroy(TipoResponsabilidad $tipo): JsonResponse
    {
        // BAJA LOGICA: Organizacion.ResponsableEess referencia este catalogo.
        $tipo->update(['TipoResponsabilidadEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de responsabilidad desactivado.'], 200);
    }
}
