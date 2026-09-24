<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LogIntegracionResource;
use App\Models\Soporte\LogIntegracion;
use Illuminate\Http\Request;

// Controller de SOLO LECTURA a proposito: los logs de integracion los escriben
// los procesos que hablan con sistemas externos, no un cliente HTTP.
class LogIntegracionController extends Controller
{
    // GET /api/logs-integracion?sistema_externo=Biometrico&operacion=SYNC&resultado=ERROR
    public function index(Request $request)
    {
        $logs = LogIntegracion::query()
            ->when($request->filled('sistema_externo'), fn ($q) =>
                $q->where('LogIntegracionSistemaExterno', $request->string('sistema_externo')->toString()))
            ->when($request->filled('operacion'), fn ($q) =>
                $q->where('LogIntegracionOperacion', $request->string('operacion')->toString()))
            ->when($request->filled('resultado'), fn ($q) =>
                $q->where('LogIntegracionResultado', $request->string('resultado')->toString()))
            ->orderByDesc('LogIntegracionFechaHora')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return LogIntegracionResource::collection($logs);
    }

    // GET /api/logs-integracion/{log}
    public function show(LogIntegracion $log)
    {
        return new LogIntegracionResource($log);
    }
}
