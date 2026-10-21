<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Seguridad\Usuario;
use App\Services\SeguridadDeCuentaService;
use App\Support\ResguardoDeAdministracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    // Roles y ambito vigentes de cada usuario: dos consultas por pagina (no una por usuario).
    /** @return array<int|string,mixed> */
    private static function relaciones(): array
    {
        return [
            'trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
            'usuarioRoles' => fn ($q) => $q->where('UsuarioRolEstado', 1)->with('rol:RolId,RolCodigo,RolNombre'),
            'ambitos' => fn ($q) => $q->where('UsuarioAmbitoEstado', 1)->with(['microred:MicroredId,MicroredNombre', 'eess:EessId,EessNombre']),
        ];
    }

    // GET /api/usuarios?buscar=mquispe|quispe|70000001&trabajador_id=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $usuarios = Usuario::query()
            ->with(self::relaciones())
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

    // POST /api/usuarios   (UsuarioEstadoCuenta: ACTIVO | INACTIVO | BLOQUEADO; la clave inicial debe cambiarse al ingresar)
    public function store(UsuarioRequest $request): JsonResponse
    {
        $usuario = Usuario::create($this->datos($request) + ['UsuarioDebeCambiarPassword' => true]);
        $this->aplicarEstadoCuenta($request, $usuario);

        return (new UsuarioResource($usuario->fresh()->load(self::relaciones())))
            ->response()
            ->setStatusCode(201);
    }

    // POST /api/usuarios/{usuario}/bloquear   { Motivo }   bloqueo sin plazo: cierra todas sus sesiones
    public function bloquear(Request $request, Usuario $usuario, SeguridadDeCuentaService $seguridad): JsonResponse
    {
        $datos = $request->validate(['Motivo' => ['required', 'string', 'min:5', 'max:200']], [], ['Motivo' => 'motivo']);
        abort_if((int) $usuario->UsuarioId === (int) $request->user()->UsuarioId, 422, 'No puedes bloquear tu propia cuenta.');
        ResguardoDeAdministracion::proteger($request->user(), fn () => $seguridad->bloquear($usuario, $datos['Motivo'], null, $request->user()->UsuarioId));

        return (new UsuarioResource($usuario->fresh()->load(self::relaciones())))->response();
    }

    // POST /api/usuarios/{usuario}/desbloquear
    public function desbloquear(Request $request, Usuario $usuario, SeguridadDeCuentaService $seguridad): JsonResponse
    {
        $seguridad->desbloquear($usuario, $request->user()->UsuarioId);

        return (new UsuarioResource($usuario->fresh()->load(self::relaciones())))->response();
    }

    /** El estado elegido en el formulario: bloquear o desactivar cierra sus sesiones abiertas. */
    private function aplicarEstadoCuenta(UsuarioRequest $request, Usuario $usuario): void
    {
        $estado = $request->input('UsuarioEstadoCuenta');
        $seguridad = app(SeguridadDeCuentaService::class);
        $actor = $request->user()?->UsuarioId;
        if ($estado === 'BLOQUEADO' && ! $usuario->UsuarioBloqueado) {
            $seguridad->bloquear($usuario, (string) $request->input('UsuarioMotivoBloqueo', 'Bloqueada por el administrador.'), null, $actor);
        } elseif ($estado !== null && $estado !== 'BLOQUEADO' && $usuario->UsuarioBloqueado) {
            $seguridad->desbloquear($usuario, (int) $actor);
        }
        if ($estado === 'INACTIVO' || ($request->has('UsuarioEstado') && ! $request->boolean('UsuarioEstado'))) {
            $seguridad->cerrarSesiones($usuario, 'REVOCADA', $actor);
        }
    }

    // GET /api/usuarios/{usuario}
    public function show(Usuario $usuario)
    {
        return new UsuarioResource($usuario->load(self::relaciones()));
    }

    // PUT|PATCH /api/usuarios/{usuario}  (la contrasena solo cambia si se envia una nueva)
    public function update(UsuarioRequest $request, Usuario $usuario)
    {
        // Una clave restablecida por el administrador se cambia en el proximo ingreso.
        ResguardoDeAdministracion::proteger($request->user(), function () use ($request, $usuario) {
            $usuario->update($this->datos($request) + ($request->filled('UsuarioPassword') ? ['UsuarioDebeCambiarPassword' => true] : []));
            $this->aplicarEstadoCuenta($request, $usuario);
        });

        return new UsuarioResource($usuario->fresh()->load(self::relaciones()));
    }

    // DELETE /api/usuarios/{usuario}
    public function destroy(Usuario $usuario): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: auditoria, justificaciones y programaciones registran quien actuo.
        abort_if((int) $usuario->UsuarioId === (int) request()->user()?->UsuarioId, 422, 'No puedes desactivar tu propia cuenta: pídeselo a otro administrador.');
        ResguardoDeAdministracion::proteger(request()->user(), function () use ($usuario) {
            $usuario->update(['UsuarioEstado' => false]);
            app(SeguridadDeCuentaService::class)->cerrarSesiones($usuario, 'REVOCADA', request()->user()?->UsuarioId);
        });

        return response()->json(['mensaje' => 'Usuario desactivado.'], 200);
    }

    /**
     * Columnas a guardar: la contrasena en texto plano y su confirmacion NO son columnas; solo se guarda el hash.
     *
     * @return array<string,mixed>
     */
    private function datos(UsuarioRequest $request): array
    {
        $datos = collect($request->validated())->except(['UsuarioPassword', 'UsuarioPasswordConfirmacion', 'UsuarioEstadoCuenta', 'UsuarioMotivoBloqueo'])->all();
        if ($request->filled('UsuarioEstadoCuenta')) {
            $datos['UsuarioEstado'] = $request->input('UsuarioEstadoCuenta') !== 'INACTIVO';
        }

        if ($request->filled('UsuarioPassword')) {
            $datos['UsuarioPasswordHash'] = Hash::make($request->string('UsuarioPassword')->toString());
        }

        return $datos;
    }
}
