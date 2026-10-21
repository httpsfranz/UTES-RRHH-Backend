<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolRequest;
use App\Http\Resources\RolResource;
use App\Models\Seguridad\Rol;
use App\Support\RegistroAuditoria;
use App\Support\ResguardoDeAdministracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RolController extends Controller
{
    // GET /api/roles?buscar=admin&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $roles = Rol::query()
            ->withCount([
                'rolPermisos as permisos_total' => fn ($q) => $q->where('RolPermisoEstado', 1),
                'usuarioRoles as usuarios_total' => fn ($q) => $q->where('UsuarioRolEstado', 1),
            ])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('RolNombre', 'like', "%{$buscar}%")
                ->orWhere('RolCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) => $q->where('RolEstado', $request->boolean('estado')))
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
        ResguardoDeAdministracion::proteger($request->user(), fn () => $rol->update($request->validated()));

        return new RolResource($rol->fresh());
    }

    // DELETE /api/roles/{rol}
    // Eliminar un rol: un rol ACTIVO no se elimina (primero se desactiva con PATCH RolEstado=false), ni uno que alguna vez
    // se asigno a un usuario (su historial de asignaciones lo referencia: queda desactivado). Los roles del sistema no se tocan.
    public function destroy(Rol $rol): JsonResponse
    {
        abort_if(in_array($rol->RolCodigo, self::ROLES_DEL_SISTEMA, true), 422, 'Es un rol del sistema: puede desactivarse, pero no eliminarse.');
        abort_if((bool) $rol->RolEstado, 422, 'Un rol activo no se puede eliminar: desactívalo primero.');
        abort_if(DB::table('Seguridad.UsuarioRol')->where('RolId', $rol->RolId)->exists(), 422, 'El rol tiene historial de asignaciones a usuarios: se conserva desactivado.');

        DB::transaction(function () use ($rol) {
            DB::table('Seguridad.RolPermiso')->where('RolId', $rol->RolId)->delete();
            $rol->delete();
        });
        RegistroAuditoria::evento('PERMISOS', 'Seguridad', 'Rol', (string) $rol->RolId, "Eliminó el rol {$rol->RolCodigo}.");

        return response()->json(['mensaje' => 'Rol eliminado.'], 200);
    }

    /** Roles que el sistema consulta por su codigo (permisos, alcance, notificaciones). */
    public const ROLES_DEL_SISTEMA = ['ADMIN', 'RRHH_RED', 'JEFE_MICRORED', 'RESP_EESS', 'PROGRAMADOR', 'TRABAJADOR', 'PORTERIA'];
}
