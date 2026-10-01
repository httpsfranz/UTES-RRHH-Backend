<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolPermisoRequest;
use App\Http\Requests\RolPermisosSincronizarRequest;
use App\Http\Resources\RolPermisoResource;
use App\Models\Seguridad\Rol;
use App\Models\Seguridad\RolPermiso;
use App\Services\RolPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolPermisoController extends Controller
{
    // GET /api/roles-permisos?rol_id=1&permiso_id=2&modulo=ASISTENCIA&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $asignaciones = RolPermiso::query()
            ->with(['rol:RolId,RolCodigo,RolNombre', 'permiso:PermisoId,PermisoCodigo,PermisoNombre,PermisoModulo'])
            ->when($request->filled('rol_id'), fn ($q) => $q->where('RolId', $request->integer('rol_id')))
            ->when($request->filled('permiso_id'), fn ($q) => $q->where('PermisoId', $request->integer('permiso_id')))
            ->when($request->filled('modulo'), fn ($q) => $q->whereHas('permiso', fn ($p) => $p->where('PermisoModulo', $request->string('modulo')->toString())))
            ->when($request->filled('estado'), fn ($q) => $q->where('RolPermisoEstado', $request->boolean('estado')))
            ->orderBy('RolId')
            ->orderBy('PermisoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return RolPermisoResource::collection($asignaciones);
    }

    // POST /api/roles-permisos
    public function store(RolPermisoRequest $request): JsonResponse
    {
        $asignacion = RolPermiso::create($request->validated());

        return (new RolPermisoResource($asignacion->fresh()->load('rol', 'permiso')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/roles-permisos/{asignacion}
    public function show(RolPermiso $asignacion)
    {
        return new RolPermisoResource($asignacion->load('rol', 'permiso'));
    }

    // PUT|PATCH /api/roles-permisos/{asignacion}
    public function update(RolPermisoRequest $request, RolPermiso $asignacion)
    {
        $asignacion->update($request->validated());

        return new RolPermisoResource($asignacion->fresh()->load('rol', 'permiso'));
    }

    // DELETE /api/roles-permisos/{asignacion}
    public function destroy(RolPermiso $asignacion): JsonResponse
    {
        // Tabla puente: aqui SI se borra la fila (quitar el permiso al rol). Nada la referencia.
        $asignacion->delete();

        return response()->json(['mensaje' => 'Permiso retirado del rol.'], 200);
    }

    // GET /api/roles/{rol}/permisos : permisos asignados (activos) del rol
    public function delRol(Rol $rol)
    {
        $asignaciones = RolPermiso::query()
            ->with('permiso:PermisoId,PermisoCodigo,PermisoNombre,PermisoModulo')
            ->where('RolId', $rol->RolId)
            ->where('RolPermisoEstado', 1)
            ->orderBy('PermisoId')
            ->get();

        return RolPermisoResource::collection($asignaciones);
    }

    // PUT /api/roles/{rol}/permisos : reemplaza el conjunto de permisos del rol
    public function sincronizar(RolPermisosSincronizarRequest $request, Rol $rol, RolPermisoService $servicio)
    {
        $resultado = $servicio->sincronizar($rol, $request->validated('PermisoIds'));

        return response()->json([
            'mensaje' => 'Permisos del rol actualizados.',
            ...$resultado,
        ]);
    }
}
