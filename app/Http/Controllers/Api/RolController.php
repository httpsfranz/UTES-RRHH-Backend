<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolRequest;
use App\Http\Resources\RolResource;
use App\Models\Seguridad\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolController extends Controller
{
    // GET /api/roles?buscar=admin&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $roles = Rol::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('RolNombre', 'like', "%{$buscar}%")
                    ->orWhere('RolCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('RolEstado', $request->boolean('estado')))
            ->orderBy('RolNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return RolResource::collection($roles);
    }

    // POST /api/roles
    public function store(RolRequest $request): JsonResponse
    {
        $rol = Rol::create($request->validated());

        return (new RolResource($rol->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/roles/{rol}
    public function show(Rol $rol)
    {
        return new RolResource($rol);
    }

    // PUT|PATCH /api/roles/{rol}
    public function update(RolRequest $request, Rol $rol)
    {
        $rol->update($request->validated());

        return new RolResource($rol->fresh());
    }

    // DELETE /api/roles/{rol}
    public function destroy(Rol $rol): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Seguridad.RolPermiso y Seguridad.UsuarioRol tienen FK hacia esta tabla.
        $rol->update(['RolEstado' => 0]);

        return response()->json(['mensaje' => 'Rol desactivado.'], 200);
    }
}
