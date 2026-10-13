<?php

namespace App\Support;

use App\Models\Seguridad\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Lo que un usuario autenticado puede hacer y ver: sus roles, sus permisos y su alcance.
 *
 * Alcance (RIT: el jefe del establecimiento y el responsable de control de asistencia responden por su personal, la Unidad
 * Funcional de RRHH por toda la Red):
 *   - global:  ADMIN, o un ambito sin microred ni establecimiento  -> todos los establecimientos;
 *   - eess:    los establecimientos de sus ambitos (un EESS, o todos los de una microred);
 *   - propio:  quien solo tiene el rol TRABAJADOR consulta unicamente su propia informacion (sus vinculos laborales).
 *
 * Se calcula una vez por peticion y se guarda en el propio modelo (no hay cache entre peticiones: los cambios de rol
 * tienen efecto inmediato).
 */
class AccesoUsuario
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permisos
     * @param  list<int>|null  $eessIds  null = todos
     * @param  list<int>  $vinculoIds  vinculos propios (solo se usa con $propio)
     */
    public function __construct(
        public readonly array $roles,
        public readonly array $permisos,
        public readonly ?array $eessIds,
        public readonly bool $propio,
        public readonly array $vinculoIds,
    ) {}

    public static function de(Usuario $usuario): self
    {
        if (isset($usuario->accesoCalculado)) {
            return $usuario->accesoCalculado;
        }

        $hoy = HoraLocal::hoy()->toDateString();
        $roles = DB::table('Seguridad.UsuarioRol as ur')
            ->join('Seguridad.Rol as r', 'r.RolId', '=', 'ur.RolId')
            ->where('ur.UsuarioId', $usuario->UsuarioId)->where('ur.UsuarioRolEstado', 1)->where('r.RolEstado', 1)
            ->whereDate('ur.UsuarioRolFechaInicio', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('ur.UsuarioRolFechaFin')->orWhereDate('ur.UsuarioRolFechaFin', '>=', $hoy))
            ->get(['r.RolId', 'r.RolCodigo']);
        $codigos = $roles->pluck('RolCodigo')->unique()->values()->all();

        $permisos = $roles->isEmpty() ? [] : DB::table('Seguridad.RolPermiso as rp')
            ->join('Seguridad.Permiso as p', 'p.PermisoId', '=', 'rp.PermisoId')
            ->whereIn('rp.RolId', $roles->pluck('RolId')->all())->where('rp.RolPermisoEstado', 1)->where('p.PermisoEstado', 1)
            ->distinct()->pluck('p.PermisoCodigo')->values()->all();

        $acceso = new self($codigos, $permisos, self::eessPermitidos($usuario, $codigos), $codigos === ['TRABAJADOR'], []);
        if ($acceso->propio) {
            $acceso = new self($codigos, $permisos, $acceso->eessIds, true, DB::table('Personal.VinculoLaboral')->where('TrabajadorId', $usuario->TrabajadorId)->pluck('VinculoLaboralId')->map(fn ($id) => (int) $id)->all());
        }

        return $usuario->accesoCalculado = $acceso;
    }

    public function tiene(string $permiso): bool
    {
        return in_array($permiso, $this->permisos, true);
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>|null
     */
    private static function eessPermitidos(Usuario $usuario, array $roles): ?array
    {
        if (in_array('ADMIN', $roles, true)) {
            return null;
        }
        $ambitos = DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $usuario->UsuarioId)->where('UsuarioAmbitoEstado', 1)->get(['MicroredId', 'EessId']);
        if ($ambitos->contains(fn ($a) => $a->MicroredId === null && $a->EessId === null)) {
            return null;
        }

        $eess = $ambitos->whereNotNull('EessId')->pluck('EessId')->all();
        $microredes = $ambitos->whereNotNull('MicroredId')->pluck('MicroredId')->all();
        if ($microredes !== []) {
            $eess = array_merge($eess, DB::table('Organizacion.EstablecimientoSalud')->whereIn('MicroredId', $microredes)->pluck('EessId')->all());
        }

        return array_values(array_unique(array_map('intval', $eess)));
    }
}
