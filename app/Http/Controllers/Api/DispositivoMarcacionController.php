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
    /**
     * GET /api/dispositivos
     * Listar dispositivos
     */
    public function index(Request $request)
    {
        $registros = DispositivoMarcacion::query()
            ->when(
                $request->filled('buscar'),
                fn ($q) =>
                    $q->where(
                        'DispositivoMarcacionNombre',
                        'like',
                        '%' . $request->string('buscar') . '%'
                    )
            )
            ->when(
                $request->filled('estado'),
                fn ($q) =>
                    $q->where(
                        'DispositivoMarcacionEstado',
                        $request->boolean('estado')
                    )
            )
            ->orderBy('DispositivoMarcacionNombre')
            ->paginate(
                $request->integer('por_pagina', 15)
            );

        return DispositivoMarcacionResource::collection($registros);
    }

    /**
     * POST /api/dispositivos
     * Crear dispositivo
     */
    public function store(DispositivoMarcacionRequest $request): JsonResponse
    {
        $registro = DispositivoMarcacion::create(
            $request->validated()
        );

        return (new DispositivoMarcacionResource($registro))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/dispositivos/{dispositivo}
     * Mostrar dispositivo
     */
    public function show(DispositivoMarcacion $dispositivo)
    {
        return new DispositivoMarcacionResource($dispositivo);
    }

    /**
     * PUT/PATCH /api/dispositivos/{dispositivo}
     * Actualizar dispositivo
     */
    public function update(
        DispositivoMarcacionRequest $request,
        DispositivoMarcacion $dispositivo
    ) {
        $dispositivo->update(
            $request->validated()
        );

        return new DispositivoMarcacionResource(
            $dispositivo->fresh()
        );
    }

    /**
     * DELETE /api/dispositivos/{dispositivo}
     * Baja lógica
     */
    public function destroy(
        DispositivoMarcacion $dispositivo
    ): JsonResponse {
        $dispositivo->update([
            'DispositivoMarcacionEstado' => false
        ]);

        return response()->json([
            'mensaje' => 'Registro desactivado correctamente.'
        ], 200);
    }
}