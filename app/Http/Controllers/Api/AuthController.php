<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seguridad\SesionAcceso;
use App\Models\Seguridad\Usuario;
use App\Services\SeguridadDeCuentaService;
use App\Support\AccesoUsuario;
use App\Support\HoraLocal;
use App\Support\RegistroAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Inicio y cierre de sesion de la API (tokens Sanctum). Cada intento deja una fila en Seguridad.SesionAcceso con su
 * resultado (EXITOSO, CLAVE_INCORRECTA, USUARIO_INACTIVO, USUARIO_BLOQUEADO) y un evento en la auditoria. Los intentos
 * fallidos seguidos bloquean la cuenta por un tiempo (SeguridadDeCuentaService); un intento contra un usuario que no
 * existe no se registra (la tabla exige un usuario) pero cuenta para el limite de intentos de la ruta.
 */
class AuthController extends Controller
{
    /** Hash de relleno: se compara aunque el usuario no exista, para que la respuesta tarde lo mismo. */
    private const HASH_DE_RELLENO = '$2y$10$.IbfgeF.ziFNknf4iEwjO.KjBum5k.mLUNc22ZfSK5tfYmY.IYVb.';

    public function __construct(private SeguridadDeCuentaService $seguridad) {}

    // POST /api/auth/login   { usuario, password }   usuario = nombre de usuario o correo
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ], [], ['usuario' => 'usuario', 'password' => 'contraseña']);

        $usuario = Usuario::query()->where('UsuarioNombre', $datos['usuario'])->orWhere('UsuarioCorreo', $datos['usuario'])->first();
        $correcta = Hash::check($datos['password'], $usuario?->UsuarioPasswordHash ?? self::HASH_DE_RELLENO) && $usuario !== null;

        if ($usuario !== null && $this->seguridad->estaBloqueada($usuario)) {
            $this->registrarSesion($usuario, $request, 'USUARIO_BLOQUEADO');
            RegistroAuditoria::evento('LOGIN_FALLIDO', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, 'Intento de ingreso con la cuenta bloqueada.', 'DENEGADO', $usuario->UsuarioId);
            $hasta = $usuario->UsuarioBloqueadoHasta ? ' hasta las '.$usuario->UsuarioBloqueadoHasta->format('H:i') : '';

            return response()->json([
                'message' => "Tu cuenta está bloqueada{$hasta}. ".($hasta ? 'Inténtalo después o comunícate con el administrador.' : 'Comunícate con el administrador.'),
                'bloqueada' => true,
            ], 423);
        }

        if ($usuario === null || ! $correcta || ! $usuario->UsuarioEstado) {
            if ($usuario !== null) {
                $this->registrarSesion($usuario, $request, $usuario->UsuarioEstado ? 'CLAVE_INCORRECTA' : 'USUARIO_INACTIVO');
                if ($usuario->UsuarioEstado && ! $correcta && $this->seguridad->intentoFallido($usuario)) {
                    return response()->json(['message' => 'Superaste los intentos permitidos: tu cuenta quedó bloqueada temporalmente.', 'bloqueada' => true], 423);
                }
            }

            return response()->json(['message' => $usuario !== null && ! $usuario->UsuarioEstado && $correcta ? 'Tu usuario está inactivo. Comunícate con Recursos Humanos.' : 'Usuario o contraseña incorrectos.'], 401);
        }

        $minutos = (int) config('sanctum.expiration', 720);
        $sesion = $this->registrarSesion($usuario, $request, 'EXITOSO', $minutos);
        $token = $usuario->createToken('sesion-'.$sesion->SesionAccesoId, ['*'], now()->addMinutes($minutos));
        $this->seguridad->accesoExitoso($usuario);
        RegistroAuditoria::evento('LOGIN', 'Seguridad', 'SesionAcceso', (string) $sesion->SesionAccesoId, 'Inicio de sesión.', 'EXITO', $usuario->UsuarioId);

        return response()->json(['token' => $token->plainTextToken] + $this->perfil($usuario->fresh()));
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
            SesionAcceso::query()->where('SesionAccesoId', (int) $m[1])->whereNull('SesionAccesoFechaFin')
                ->update(['SesionAccesoFechaFin' => HoraLocal::ahora(), 'SesionAccesoMotivoCierre' => 'LOGOUT']);
            RegistroAuditoria::evento('LOGOUT', 'Seguridad', 'SesionAcceso', $m[1], 'Cierre de sesión.');
        }
        $token?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    // POST /api/auth/cambiar-password   { PasswordActual, PasswordNueva, PasswordConfirmacion }
    // La cambia el propio usuario (obligatorio en el primer ingreso o cuando el administrador la restablecio).
    public function cambiarPassword(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'PasswordActual' => ['required', 'string', 'max:200'],
            'PasswordNueva' => ['required', 'string', 'max:72', 'different:PasswordActual', Password::min(8)->letters()->numbers()],
            'PasswordConfirmacion' => ['required', 'same:PasswordNueva'],
        ], [
            'PasswordNueva.different' => 'La nueva contraseña debe ser distinta de la actual.',
            'PasswordConfirmacion.same' => 'La confirmación no coincide con la nueva contraseña.',
        ], ['PasswordActual' => 'contraseña actual', 'PasswordNueva' => 'nueva contraseña', 'PasswordConfirmacion' => 'confirmación']);

        $usuario = $request->user();
        if (! Hash::check($datos['PasswordActual'], $usuario->UsuarioPasswordHash)) {
            RegistroAuditoria::evento('CAMBIO_PASSWORD', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, 'Contraseña actual incorrecta.', 'FALLO');
            throw ValidationException::withMessages(['PasswordActual' => 'La contraseña actual no es correcta.']);
        }
        $usuario->update(['UsuarioPasswordHash' => Hash::make($datos['PasswordNueva']), 'UsuarioDebeCambiarPassword' => false, 'UsuarioFechaCambioPassword' => HoraLocal::ahora()]);
        RegistroAuditoria::evento('CAMBIO_PASSWORD', 'Seguridad', 'Usuario', (string) $usuario->UsuarioId, 'El usuario cambió su contraseña.');

        return response()->json(['message' => 'Contraseña actualizada.'] + $this->perfil($usuario->fresh()));
    }

    private function registrarSesion(Usuario $usuario, Request $request, string $resultado, ?int $minutos = null): SesionAcceso
    {
        $ahora = HoraLocal::ahora();

        return SesionAcceso::query()->create([
            'UsuarioId' => $usuario->UsuarioId,
            'SesionAccesoFechaInicio' => $ahora,
            'SesionAccesoDireccionIp' => $request->ip(),
            'SesionAccesoResultado' => $resultado,
            'SesionAccesoAgente' => mb_substr((string) $request->userAgent(), 0, 300) ?: null,
            'SesionAccesoUltimaActividad' => $resultado === 'EXITOSO' ? $ahora : null,
            'SesionAccesoExpiraEn' => $minutos ? $ahora->addMinutes($minutos) : null,
            // Un intento fallido no abre sesion: queda registrado ya cerrado.
            'SesionAccesoFechaFin' => $resultado === 'EXITOSO' ? null : $ahora,
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
                'debe_cambiar_clave' => (bool) $usuario->UsuarioDebeCambiarPassword,
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
