<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRolRequest;
use App\Http\Resources\UsuarioRolResource;
use App\Models\Seguridad\UsuarioRol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioRolController extends Controller
{
    private const RELACIONES = [
        'usuario:UsuarioId,UsuarioNombre,TrabajadorId',
        'usuario.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'rol:RolId,RolCodigo,RolNombre',
    ];

    // GET /api/usuarios-roles?buscar=...&usuario_id=&rol_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = UsuarioRol::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('usuario', fn ($t) => $t
                    ->where('UsuarioNombre', 'like', "%{$buscar}%")
                    ->orWhereHas('trabajador', fn ($w) => $w
                        ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                        ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('rol_id'), fn ($q) => $q->where('RolId', $request->integer('rol_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('UsuarioRolEstado', $request->boolean('estado')))

            ->orderBy('UsuarioId')
            ->orderByDesc('UsuarioRolFechaInicio')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return UsuarioRolResource::collection($registros);
    }

    // POST /api/usuarios-roles
    public function store(UsuarioRolRequest $request): JsonResponse
    {
        $asignacion = UsuarioRol::create($request->datos());

        return (new UsuarioRolResource($asignacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/usuarios-roles/{asignacion}
    public function show(UsuarioRol $asignacion)
    {
        return new UsuarioRolResource($asignacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/usuarios-roles/{asignacion}
    public function update(UsuarioRolRequest $request, UsuarioRol $asignacion)
    {
        $asignacion->update($request->datos());

        return new UsuarioRolResource($asignacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/usuarios-roles/{asignacion}
    public function destroy(UsuarioRol $asignacion): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial se conserva.
        $asignacion->update(['UsuarioRolEstado' => false]);

        return response()->json(['mensaje' => 'Rol del usuario desactivado.'], 200);
    }
}
