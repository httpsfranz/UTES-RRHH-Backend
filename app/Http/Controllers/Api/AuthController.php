<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seguridad\SesionAcceso;
use App\Models\Seguridad\Usuario;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Inicio y cierre de sesion de la API (tokens Sanctum). Cada intento deja una fila en Seguridad.SesionAcceso con su
 * resultado (EXITOSO, CLAVE_INCORRECTA, USUARIO_INACTIVO); un intento contra un usuario que no existe no se registra
 * (la tabla exige un usuario) pero si cuenta para el limite de intentos de la ruta.
 */
class AuthController extends Controller
{
    /** Hash de relleno: se compara aunque el usuario no exista, para que la respuesta tarde lo mismo. */
    private const HASH_DE_RELLENO = '$2y$10$.IbfgeF.ziFNknf4iEwjO.KjBum5k.mLUNc22ZfSK5tfYmY.IYVb.';

    // POST /api/auth/login   { usuario, password }   usuario = nombre de usuario o correo
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ], [], ['usuario' => 'usuario', 'password' => 'contraseña']);

        $usuario = Usuario::query()->where('UsuarioNombre', $datos['usuario'])->orWhere('UsuarioCorreo', $datos['usuario'])->first();
        $correcta = Hash::check($datos['password'], $usuario?->UsuarioPasswordHash ?? self::HASH_DE_RELLENO) && $usuario !== null;

        if ($usuario === null || ! $correcta || ! $usuario->UsuarioEstado) {
            if ($usuario !== null) {
                $this->registrarSesion($usuario, $request, $usuario->UsuarioEstado ? 'CLAVE_INCORRECTA' : 'USUARIO_INACTIVO');
            }

            return response()->json(['message' => $usuario !== null && ! $usuario->UsuarioEstado && $correcta ? 'Tu usuario está inactivo. Comunícate con Recursos Humanos.' : 'Usuario o contraseña incorrectos.'], 401);
        }

        $sesion = $this->registrarSesion($usuario, $request, 'EXITOSO');
        $token = $usuario->createToken('sesion-'.$sesion->SesionAccesoId, ['*'], now()->addMinutes((int) config('sanctum.expiration', 720)));

        return response()->json(['token' => $token->plainTextToken] + $this->perfil($usuario));
    }

    // GET /api/auth/me
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->perfil($request->user()));
    }

    // POST /api/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token !== null && preg_match('/^sesion-(\d+)$/', (string) $token->name, $m)) {
            SesionAcceso::query()->where('SesionAccesoId', (int) $m[1])->whereNull('SesionAccesoFechaFin')->update(['SesionAccesoFechaFin' => HoraLocal::ahora()]);
        }
        $token?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function registrarSesion(Usuario $usuario, Request $request, string $resultado): SesionAcceso
    {
        return SesionAcceso::query()->create([
            'UsuarioId' => $usuario->UsuarioId,
            'SesionAccesoFechaInicio' => HoraLocal::ahora(),
            'SesionAccesoDireccionIp' => $request->ip(),
            'SesionAccesoResultado' => $resultado,
        ]);
    }

    /** @return array<string,mixed> */
    private function perfil(Usuario $usuario): array
    {
        $acceso = AccesoUsuario::de($usuario);
        $trabajador = DB::table('Personal.Trabajador')->where('TrabajadorId', $usuario->TrabajadorId)->first(['TrabajadorId', 'TrabajadorNumeroDocumento', 'TrabajadorNombreCompleto']);

        return [
            'usuario' => [
                'id' => (int) $usuario->UsuarioId,
                'nombre' => $usuario->UsuarioNombre,
                'correo' => $usuario->UsuarioCorreo,
                'trabajador' => $trabajador ? [
                    'id' => (int) $trabajador->TrabajadorId,
                    'numero_documento' => $trabajador->TrabajadorNumeroDocumento,
                    'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $trabajador->TrabajadorNombreCompleto))),
                ] : null,
            ],
            'roles' => DB::table('Seguridad.Rol')->whereIn('RolCodigo', $acceso->roles)->get(['RolCodigo', 'RolNombre'])->map(fn ($r) => ['codigo' => $r->RolCodigo, 'nombre' => $r->RolNombre])->all(),
            'permisos' => $acceso->permisos,
            'alcance' => [
                'global' => $acceso->eessIds === null,
                'propio' => $acceso->propio,
                'eess_ids' => $acceso->eessIds,
            ],
        ];
    }
}
