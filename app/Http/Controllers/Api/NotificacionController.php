<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificacionRequest;
use App\Http\Resources\NotificacionResource;
use App\Models\Soporte\Notificacion;
use App\Services\NotificacionService;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
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
            ->when(! $this->veTodas($request), fn ($q) => $q->where('UsuarioId', $request->user()->UsuarioId))
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
    public function show(Request $request, Notificacion $notificacion)
    {
        $this->exigirPropia($request, $notificacion);

        return new NotificacionResource($notificacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/notificaciones/{notificacion}
    public function update(NotificacionRequest $request, Notificacion $notificacion)
    {
        $this->exigirPropia($request, $notificacion);
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

    // GET /api/notificaciones/bandeja   las ultimas notificaciones del usuario de la sesion y cuantas no ha leido (campana)
    public function bandeja(Request $request): JsonResponse
    {
        $propias = Notificacion::query()->where('UsuarioId', $request->user()->UsuarioId);

        return response()->json([
            'no_leidas' => (clone $propias)->where('NotificacionLeida', 0)->count(),
            'data' => NotificacionResource::collection((clone $propias)->orderBy('NotificacionLeida')->orderByDesc('NotificacionFecha')->orderByDesc('NotificacionId')->limit(15)->get()),
        ]);
    }

    // POST /api/notificaciones/{notificacion}/leer
    public function leer(Request $request, Notificacion $notificacion): JsonResponse
    {
        abort_unless((int) $notificacion->UsuarioId === (int) $request->user()->UsuarioId, 403, 'Solo puedes marcar tus propias notificaciones.');
        if (! $notificacion->NotificacionLeida) {
            $notificacion->update(['NotificacionLeida' => true, 'NotificacionFechaLectura' => HoraLocal::ahora()]);
        }

        return (new NotificacionResource($notificacion->fresh()))->response();
    }

    // POST /api/notificaciones/leer-todas
    public function leerTodas(Request $request): JsonResponse
    {
        $marcadas = Notificacion::query()->where('UsuarioId', $request->user()->UsuarioId)->where('NotificacionLeida', 0)
            ->update(['NotificacionLeida' => 1, 'NotificacionFechaLectura' => HoraLocal::ahora()]);

        return response()->json(['mensaje' => "{$marcadas} notificación(es) marcadas como leídas."]);
    }

    // POST /api/notificaciones/{notificacion}/reintentar   vuelve a intentar el correo de una notificacion FALLIDA
    public function reintentar(Notificacion $notificacion, NotificacionService $servicio): JsonResponse
    {
        abort_unless($notificacion->NotificacionCorreoEstado === 'FALLIDO', 422, 'Solo se reintenta un correo que falló.');
        $notificacion->update(['NotificacionCorreoEstado' => 'PENDIENTE', 'NotificacionCorreoIntentos' => 0]);
        $servicio->enviarCorreo($notificacion->NotificacionId);

        return (new NotificacionResource($notificacion->fresh()->load(self::RELACIONES)))->response();
    }

    /** Quien tiene SEGURIDAD_VER administra las notificaciones de todos; los demas solo ven y marcan las suyas. */
    private function veTodas(Request $request): bool
    {
        return AccesoUsuario::de($request->user())->tiene('SEGURIDAD_VER');
    }

    private function exigirPropia(Request $request, Notificacion $notificacion): void
    {
        abort_unless($this->veTodas($request) || (int) $notificacion->UsuarioId === (int) $request->user()->UsuarioId, 403, 'Solo puedes ver tus propias notificaciones.');
    }
}
