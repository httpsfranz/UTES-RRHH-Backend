<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoFaltaDisciplinariaRequest;
use App\Http\Resources\TipoFaltaDisciplinariaResource;
use App\Models\Disciplina\TipoFaltaDisciplinaria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoFaltaDisciplinariaController extends Controller
{
    // GET /api/tipos-falta-disciplinaria?buscar=abandono&gravedad=GRAVE&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $tipos = TipoFaltaDisciplinaria::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('TipoFaltaDisciplinariaNombre', 'like', "%{$buscar}%")
                    ->orWhere('TipoFaltaDisciplinariaCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('gravedad'), fn ($q) =>
                $q->where('TipoFaltaDisciplinariaGravedad', $request->string('gravedad')->upper()))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('TipoFaltaDisciplinariaEstado', $request->boolean('estado')))
            ->orderBy('TipoFaltaDisciplinariaNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TipoFaltaDisciplinariaResource::collection($tipos);
    }

    // POST /api/tipos-falta-disciplinaria
    public function store(TipoFaltaDisciplinariaRequest $request): JsonResponse
    {
        $tipo = TipoFaltaDisciplinaria::create($request->validated());

        return (new TipoFaltaDisciplinariaResource($tipo->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/tipos-falta-disciplinaria/{tipo}
    public function show(TipoFaltaDisciplinaria $tipo)
    {
        return new TipoFaltaDisciplinariaResource($tipo);
    }

    // PUT|PATCH /api/tipos-falta-disciplinaria/{tipo}
    public function update(TipoFaltaDisciplinariaRequest $request, TipoFaltaDisciplinaria $tipo)
    {
        $tipo->update($request->validated());

        return new TipoFaltaDisciplinariaResource($tipo->fresh());
    }

    // DELETE /api/tipos-falta-disciplinaria/{tipo}
    public function destroy(TipoFaltaDisciplinaria $tipo): JsonResponse
    {
        // BAJA LOGICA: Disciplina.ExpedientePad referencia este catalogo.
        $tipo->update(['TipoFaltaDisciplinariaEstado' => 0]);

        return response()->json(['mensaje' => 'Tipo de falta disciplinaria desactivado.'], 200);
    }
}
