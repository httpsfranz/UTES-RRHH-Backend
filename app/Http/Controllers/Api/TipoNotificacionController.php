<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoNotificacionRequest;
use App\Http\Resources\TipoNotificacionResource;
use App\Models\Soporte\TipoNotificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tipos de notificacion y sus reglas de envio (a que roles, en que ambito, si va por correo). */
class TipoNotificacionController extends Controller
{
    // GET /api/tipos-notificacion?buscar=&estado=
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        return TipoNotificacionResource::collection(TipoNotificacion::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s->where('TipoNotificacionNombre', 'like', "%{$buscar}%")->orWhere('TipoNotificacionCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) => $q->where('TipoNotificacionEstado', $request->boolean('estado')))
            ->orderBy('TipoNotificacionNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100))));
    }

    // POST /api/tipos-notificacion
    public function store(TipoNotificacionRequest $request): JsonResponse
    {
        return (new TipoNotificacionResource(TipoNotificacion::create($request->validated())->fresh()))->response()->setStatusCode(201);
    }

    // GET /api/tipos-notificacion/{tipo}
    public function show(TipoNotificacion $tipo)
    {
        return new TipoNotificacionResource($tipo);
    }

    // PUT|PATCH /api/tipos-notificacion/{tipo}
    public function update(TipoNotificacionRequest $request, TipoNotificacion $tipo)
    {
        $tipo->update($request->validated());

        return new TipoNotificacionResource($tipo->fresh());
    }

    // DELETE /api/tipos-notificacion/{tipo}   baja logica: los avisos ya enviados conservan su tipo
    public function destroy(TipoNotificacion $tipo): JsonResponse
    {
        $tipo->update(['TipoNotificacionEstado' => false]);

        return response()->json(['mensaje' => 'Tipo de notificación desactivado.']);
    }
}
