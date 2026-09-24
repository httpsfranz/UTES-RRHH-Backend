<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermisoRequest;
use App\Http\Resources\PermisoResource;
use App\Models\Seguridad\Permiso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermisoController extends Controller
{
    // GET /api/permisos?buscar=usuario&modulo=Seguridad&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $permisos = Permiso::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('PermisoNombre', 'like', "%{$buscar}%")
                    ->orWhere('PermisoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('modulo'), fn ($q) =>
                $q->where('PermisoModulo', $request->string('modulo')->toString()))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('PermisoEstado', $request->boolean('estado')))
            ->orderBy('PermisoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return PermisoResource::collection($permisos);
    }

    // POST /api/permisos
    public function store(PermisoRequest $request): JsonResponse
    {
        $permiso = Permiso::create($request->validated());

        return (new PermisoResource($permiso->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/permisos/{permiso}
    public function show(Permiso $permiso)
    {
        return new PermisoResource($permiso);
    }

    // PUT|PATCH /api/permisos/{permiso}
    public function update(PermisoRequest $request, Permiso $permiso)
    {
        $permiso->update($request->validated());

        return new PermisoResource($permiso->fresh());
    }

    // DELETE /api/permisos/{permiso}
    public function destroy(Permiso $permiso): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Seguridad.RolPermiso tiene una FK hacia esta tabla.
        $permiso->update(['PermisoEstado' => 0]);

        return response()->json(['mensaje' => 'Permiso desactivado.'], 200);
    }
}
