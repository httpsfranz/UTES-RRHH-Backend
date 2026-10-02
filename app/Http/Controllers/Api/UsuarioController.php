<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Seguridad\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    // GET /api/usuarios?buscar=mquispe|quispe|70000001&trabajador_id=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $usuarios = Usuario::query()
            ->with('trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto')
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('UsuarioNombre', 'like', "%{$buscar}%")
                ->orWhere('UsuarioCorreo', 'like', "%{$buscar}%")
                ->orWhereHas('trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('UsuarioEstado', $request->boolean('estado')))
            ->orderBy('UsuarioNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return UsuarioResource::collection($usuarios);
    }

    // POST /api/usuarios
    public function store(UsuarioRequest $request): JsonResponse
    {
        $usuario = Usuario::create($this->datos($request));

        return (new UsuarioResource($usuario->fresh()->load('trabajador')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/usuarios/{usuario}
    public function show(Usuario $usuario)
    {
        return new UsuarioResource($usuario->load('trabajador'));
    }

    // PUT|PATCH /api/usuarios/{usuario}  (la contrasena solo cambia si se envia una nueva)
    public function update(UsuarioRequest $request, Usuario $usuario)
    {
        $usuario->update($this->datos($request));

        return new UsuarioResource($usuario->fresh()->load('trabajador'));
    }

    // DELETE /api/usuarios/{usuario}
    public function destroy(Usuario $usuario): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: auditoria, justificaciones y programaciones registran quien actuo.
        $usuario->update(['UsuarioEstado' => false]);

        return response()->json(['mensaje' => 'Usuario desactivado.'], 200);
    }

    /**
     * Columnas a guardar: la contrasena en texto plano y su confirmacion NO son columnas; solo se guarda el hash.
     *
     * @return array<string,mixed>
     */
    private function datos(UsuarioRequest $request): array
    {
        $datos = collect($request->validated())->except(['UsuarioPassword', 'UsuarioPasswordConfirmacion'])->all();

        if ($request->filled('UsuarioPassword')) {
            $datos['UsuarioPasswordHash'] = Hash::make($request->string('UsuarioPassword')->toString());
        }

        return $datos;
    }
}
