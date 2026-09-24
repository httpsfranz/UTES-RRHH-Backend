<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditoriaResource;
use App\Models\Seguridad\Auditoria;
use Illuminate\Http\Request;

// Controller de SOLO LECTURA a proposito: un registro de auditoria no se crea,
// edita ni borra via API. Lo escribe el propio sistema (trigger o servicio interno)
// cuando ocurre un INSERT/UPDATE/DELETE auditable en otra tabla.
class AuditoriaController extends Controller
{
    // GET /api/auditoria?esquema=Organizacion&tabla=Microred&operacion=UPDATE&usuario_id=3&desde=...&hasta=...
    public function index(Request $request)
    {
        $auditorias = Auditoria::query()
            ->when($request->filled('esquema'), fn ($q) =>
                $q->where('AuditoriaEsquema', $request->string('esquema')->toString()))
            ->when($request->filled('tabla'), fn ($q) =>
                $q->where('AuditoriaTabla', $request->string('tabla')->toString()))
            ->when($request->filled('operacion'), fn ($q) =>
                $q->where('AuditoriaOperacion', $request->string('operacion')->toString()))
            ->when($request->filled('usuario_id'), fn ($q) =>
                $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('desde'), fn ($q) =>
                $q->where('AuditoriaFechaHora', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) =>
                $q->where('AuditoriaFechaHora', '<=', $request->date('hasta')))
            ->orderByDesc('AuditoriaFechaHora')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return AuditoriaResource::collection($auditorias);
    }

    // GET /api/auditoria/{auditoria}
    public function show(Auditoria $auditoria)
    {
        return new AuditoriaResource($auditoria);
    }
}
