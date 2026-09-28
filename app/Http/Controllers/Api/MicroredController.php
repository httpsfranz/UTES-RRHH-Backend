<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MicroredRequest;
use App\Http\Resources\MicroredResource;
use App\Models\Organizacion\Microred;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicroredController extends Controller
{
    // GET /api/microredes?buscar=esperanza&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $microredes = Microred::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('MicroredNombre', 'like', "%{$buscar}%")
                    ->orWhere('MicroredCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('MicroredEstado', $request->boolean('estado')))
            ->orderBy('MicroredNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return MicroredResource::collection($microredes);
    }

    // POST /api/microredes
    public function store(MicroredRequest $request): JsonResponse
    {
        $microred = Microred::create($request->validated());

        return (new MicroredResource($microred->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/microredes/{microred}
    public function show(Microred $microred)
    {
        return new MicroredResource($microred);
    }

    // PUT|PATCH /api/microredes/{microred}
    public function update(MicroredRequest $request, Microred $microred)
    {
        $microred->update($request->validated());

        return new MicroredResource($microred->fresh());
    }

    // DELETE /api/microredes/{microred}
    public function destroy(Microred $microred): JsonResponse
    {
        $microred->update(['MicroredEstado' => false]);

        return response()->json(['mensaje' => 'Microred desactivada correctamente.'], 200);
    }
}