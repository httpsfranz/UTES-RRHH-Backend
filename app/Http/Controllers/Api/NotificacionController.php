<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificacionRequest;
use App\Http\Resources\NotificacionResource;
use App\Models\Soporte\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    private const RELACIONES = [
        'usuario:UsuarioId,UsuarioNombre',
    ];

    // GET /api/notificaciones?buscar=...&usuario_id=&tipo=&leida=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = Notificacion::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('NotificacionTitulo', 'like', "%{$buscar}%")
                ->orWhere('NotificacionMensaje', 'like', "%{$buscar}%")
                ->orWhere('NotificacionTipo', 'like', "%{$buscar}%")))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('NotificacionTipo', $request->string('tipo')->toString()))
            ->when($request->filled('leida'), fn ($q) => $q->where('NotificacionLeida', $request->boolean('leida')))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('NotificacionFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('NotificacionFecha', '<=', $request->date('hasta')))
            ->orderByDesc('NotificacionFecha')
            ->orderByDesc('NotificacionId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return NotificacionResource::collection($registros);
    }

    // POST /api/notificaciones
    public function store(NotificacionRequest $request): JsonResponse
    {
        $notificacion = Notificacion::create($request->datos());

        return (new NotificacionResource($notificacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/notificaciones/{notificacion}
    public function show(Notificacion $notificacion)
    {
        return new NotificacionResource($notificacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/notificaciones/{notificacion}
    public function update(NotificacionRequest $request, Notificacion $notificacion)
    {
        $notificacion->update($request->datos());

        return new NotificacionResource($notificacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/notificaciones/{notificacion}
    public function destroy(Notificacion $notificacion): JsonResponse
    {
        // DELETE fisico: si otra tabla referencia la fila, ErroresDeBaseDeDatos responde 409.
        $notificacion->delete();

        return response()->json(['mensaje' => 'Notificación eliminada.'], 200);
    }
}
