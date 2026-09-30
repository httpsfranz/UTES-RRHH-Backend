<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DispositivoMarcacionRequest;
use App\Http\Resources\DispositivoMarcacionResource;
use App\Models\Biometria\DispositivoMarcacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispositivoMarcacionController extends Controller
{
    // GET /api/dispositivos-marcacion?buscar=lector&eess_id=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $dispositivos = DispositivoMarcacion::query()
            ->with('eess:EessId,EessCodigo,EessNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('DispositivoMarcacionNombre', 'like', "%{$buscar}%")
                ->orWhere('DispositivoMarcacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('DispositivoMarcacionEstado', $request->boolean('estado')))
            ->orderBy('DispositivoMarcacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DispositivoMarcacionResource::collection($dispositivos);
    }

    // POST /api/dispositivos-marcacion
    public function store(DispositivoMarcacionRequest $request): JsonResponse
    {
        $dispositivo = DispositivoMarcacion::create($request->validated());

        return (new DispositivoMarcacionResource($dispositivo->fresh()->load('eess')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/dispositivos-marcacion/{dispositivo}
    public function show(DispositivoMarcacion $dispositivo)
    {
        return new DispositivoMarcacionResource($dispositivo->load('eess'));
    }

    // PUT|PATCH /api/dispositivos-marcacion/{dispositivo}
    public function update(DispositivoMarcacionRequest $request, DispositivoMarcacion $dispositivo)
    {
        $dispositivo->update($request->validated());

        return new DispositivoMarcacionResource($dispositivo->fresh()->load('eess'));
    }

    // DELETE /api/dispositivos-marcacion/{dispositivo}
    public function destroy(DispositivoMarcacion $dispositivo): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Asistencia.Marcacion referencia el dispositivo.
        $dispositivo->update(['DispositivoMarcacionEstado' => false]);

        return response()->json(['mensaje' => 'Dispositivo de marcación desactivado.'], 200);
    }
}
