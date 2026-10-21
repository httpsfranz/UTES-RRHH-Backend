<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Permisos, matriz rol-permiso y usuarios predeterminados. Es idempotente y NO borra nada: puede correr en produccion
 * (php artisan db:seed --class=UsuariosYPermisosSeeder) y vuelve a correr sin duplicar ni pisar contrasenas.
 *
 * Quien hace que, segun el Reglamento Interno de Trabajo (UE 409 Salud Trujillo Este):
 *   - Unidad Funcional de Gestion de RRHH (RRHH_RED): control de asistencia y permanencia de toda la Red; registra
 *     licencias y permisos, consolida y remite descuentos (Art. 24, 25, 41). No administra usuarios ni parametros.
 *   - Jefe del establecimiento / responsable de control de asistencia (RESP_EESS): revisa y aprueba la programacion de
 *     turnos y sus cambios (Art. 16, 19, 20), autoriza papeletas y permisos (Art. 21, 40), informa las faltas (Art. 23,
 *     27) y consolida la asistencia de su establecimiento. Solo ve el suyo.
 *   - Responsable de programacion (PROGRAMADOR): elabora y remite la programacion; la aprobacion es del jefe (Art. 16).
 *   - Jefe de microred (JEFE_MICRORED): supervisa y aprueba en los establecimientos de su microred, sin editar datos.
 *   - Trabajador: solo consulta su propia asistencia y solicitudes.
 *   - Porteria: registra ocurrencias y papeletas de salida.
 *   - Administrador del sistema (ADMIN): todo, incluidos usuarios, parametros y auditoria.
 *
 * Contrasena de los usuarios nuevos: USUARIOS_PASSWORD del .env (por defecto "Prueba2026*" fuera de produccion; en
 * produccion, si no se define, se genera una al azar y se muestra una sola vez). Cambiarla despues del primer ingreso.
 */
class UsuariosYPermisosSeeder extends Seeder
{
    use SiembraCatalogos;

    private const MODULOS = ['ORGANIZACION', 'PERSONAL', 'ASISTENCIA', 'PROGRAMACION', 'SOLICITUDES', 'CONFIGURACION', 'SEGURIDAD', 'REPORTES'];

    /** @var array<string,list<string>> */
    private const EXTRAS = [
        'ASISTENCIA_JUSTIFICAR' => ['ASISTENCIA', 'Justificar faltas', 'Registrar justificaciones de inasistencia'],
        'ASISTENCIA_APROBAR' => ['ASISTENCIA', 'Aprobar asistencia', 'Validar marcaciones y justificaciones'],
        'ASISTENCIA_MARCAR' => ['ASISTENCIA', 'Marcar asistencia manual', 'Registrar la entrada y la salida del personal con la hora del sistema (control manual, RIT Art. 21)'],
        'SOLICITUDES_APROBAR' => ['SOLICITUDES', 'Aprobar solicitudes', 'Aprobar papeletas, licencias y descansos'],
        'PROGRAMACION_APROBAR' => ['PROGRAMACION', 'Aprobar programación', 'Publicar y cerrar la programación de turnos (jefe del establecimiento)'],
        'REPORTES_EXPORTAR' => ['REPORTES', 'Exportar reportes', 'Descargar reportes en Excel y PDF'],
        'SEGURIDAD_AUDITORIA' => ['SEGURIDAD', 'Ver auditoría', 'Consultar el registro de auditoría'],
        'SISTEMA_PARAMETROS' => ['CONFIGURACION', 'Editar parámetros del sistema', 'Modificar los parámetros globales'],
    ];

    /** @var array<string,list<string>> */
    private const MATRIZ = [
        'RRHH_RED' => [
            'ORGANIZACION_VER', 'ORGANIZACION_EDITAR', 'PERSONAL_VER', 'PERSONAL_EDITAR', 'ASISTENCIA_VER', 'ASISTENCIA_EDITAR', 'ASISTENCIA_JUSTIFICAR', 'ASISTENCIA_APROBAR', 'ASISTENCIA_MARCAR',
            'PROGRAMACION_VER', 'PROGRAMACION_EDITAR', 'PROGRAMACION_APROBAR', 'SOLICITUDES_VER', 'SOLICITUDES_EDITAR', 'SOLICITUDES_APROBAR', 'CONFIGURACION_VER', 'CONFIGURACION_EDITAR',
            'REPORTES_VER', 'REPORTES_EDITAR', 'REPORTES_EXPORTAR',
        ],
        'JEFE_MICRORED' => ['ORGANIZACION_VER', 'PERSONAL_VER', 'ASISTENCIA_VER', 'ASISTENCIA_APROBAR', 'SOLICITUDES_VER', 'SOLICITUDES_APROBAR', 'PROGRAMACION_VER', 'REPORTES_VER', 'REPORTES_EXPORTAR'],
        'RESP_EESS' => [
            'ORGANIZACION_VER', 'PERSONAL_VER', 'ASISTENCIA_VER', 'ASISTENCIA_EDITAR', 'ASISTENCIA_JUSTIFICAR', 'ASISTENCIA_APROBAR', 'ASISTENCIA_MARCAR', 'SOLICITUDES_VER', 'SOLICITUDES_EDITAR', 'SOLICITUDES_APROBAR',
            'PROGRAMACION_VER', 'PROGRAMACION_EDITAR', 'PROGRAMACION_APROBAR', 'REPORTES_VER', 'REPORTES_EXPORTAR',
        ],
        'PROGRAMADOR' => ['PERSONAL_VER', 'ASISTENCIA_VER', 'PROGRAMACION_VER', 'PROGRAMACION_EDITAR'],
        'TRABAJADOR' => ['ASISTENCIA_VER', 'SOLICITUDES_VER'],
        'PORTERIA' => ['SOLICITUDES_VER', 'SOLICITUDES_EDITAR'],
    ];

    /** usuario => [rol, nombres, apellido paterno, ambito: global|microred|eess|ninguno] */
    private const USUARIOS = [
        'admin' => ['ADMIN', 'Administrador', 'Del Sistema', 'global'],
        'rrhh' => ['RRHH_RED', 'Recursos Humanos', 'Red Trujillo', 'global'],
        'jefe.microred' => ['JEFE_MICRORED', 'Jefe', 'De Microred', 'microred'],
        'resp.eess' => ['RESP_EESS', 'Responsable', 'De Establecimiento', 'eess'],
        'programador' => ['PROGRAMADOR', 'Responsable', 'De Programación', 'eess'],
        'trabajador' => ['TRABAJADOR', 'Trabajador', 'De Prueba', 'eess'],
        'porteria' => ['PORTERIA', 'Personal', 'De Portería', 'eess'],
    ];

    public function run(): void
    {
        $this->permisos();
        $this->matriz();
        $this->usuarios();
    }

    private function permisos(): void
    {
        $filas = [];
        foreach (self::MODULOS as $modulo) {
            $nombre = strtolower($modulo);
            $filas[] = ['PermisoCodigo' => "{$modulo}_VER", 'PermisoNombre' => "Ver {$nombre}", 'PermisoModulo' => $modulo, 'PermisoDescripcion' => "Consultar el módulo de {$nombre}"];
            $filas[] = ['PermisoCodigo' => "{$modulo}_EDITAR", 'PermisoNombre' => "Editar {$nombre}", 'PermisoModulo' => $modulo, 'PermisoDescripcion' => "Crear y modificar datos del módulo de {$nombre}"];
        }
        foreach (self::EXTRAS as $codigo => [$modulo, $nombre, $descripcion]) {
            $filas[] = ['PermisoCodigo' => $codigo, 'PermisoNombre' => $nombre, 'PermisoModulo' => $modulo, 'PermisoDescripcion' => $descripcion];
        }
        $this->sembrar('Seguridad.Permiso', 'PermisoCodigo', $filas);
    }

    private function matriz(): void
    {
        $roles = $this->ids('Seguridad.Rol', 'RolCodigo', 'RolId');
        $permisos = $this->ids('Seguridad.Permiso', 'PermisoCodigo', 'PermisoId');
        $porRol = self::MATRIZ + ['ADMIN' => array_keys(array_filter($permisos, fn ($id, $codigo) => $codigo !== 'LEGADO_IMPORTAR', ARRAY_FILTER_USE_BOTH))];

        foreach ($porRol as $rol => $codigos) {
            if (! isset($roles[$rol])) {
                continue;
            }
            foreach ($codigos as $codigo) {
                $fila = ['RolId' => $roles[$rol], 'PermisoId' => $permisos[$codigo]];
                if (! DB::table('Seguridad.RolPermiso')->where($fila)->exists()) {
                    DB::table('Seguridad.RolPermiso')->insert($fila);
                }
            }
        }
    }

    private function usuarios(): void
    {
        $roles = $this->ids('Seguridad.Rol', 'RolCodigo', 'RolId');
        $documento = DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadCodigo', 'DNI')->value('TipoDocumentoIdentidadId');
        $microred = DB::table('Organizacion.Microred')->orderBy('MicroredId')->value('MicroredId');
        $eess = DB::table('Organizacion.EstablecimientoSalud')->orderBy('EessId')->value('EessId');
        $clave = $this->clave();
        $indice = 0;

        foreach (self::USUARIOS as $nombre => [$rol, $nombres, $apellido, $ambito]) {
            $indice++;
            if (! isset($roles[$rol]) || $documento === null) {
                continue;
            }
            $usuarioId = DB::table('Seguridad.Usuario')->where('UsuarioNombre', $nombre)->value('UsuarioId');
            if ($usuarioId === null) {
                $dni = '9900000'.$indice;
                $trabajadorId = ($rol === 'TRABAJADOR' ? $this->trabajadorConDatos($eess) : null)
                    ?? DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', $dni)->value('TrabajadorId')
                    ?? DB::table('Personal.Trabajador')->insertGetId([
                        'TipoDocumentoIdentidadId' => $documento, 'TrabajadorNumeroDocumento' => $dni, 'TrabajadorNombres' => $nombres,
                        'TrabajadorApellidoPaterno' => $apellido, 'TrabajadorApellidoMaterno' => 'Predeterminado',
                    ], 'TrabajadorId');
                $usuarioId = DB::table('Seguridad.Usuario')->insertGetId([
                    'TrabajadorId' => $trabajadorId, 'UsuarioNombre' => $nombre, 'UsuarioPasswordHash' => Hash::make($clave), 'UsuarioCorreo' => str_replace('.', '', $nombre).'@redsalud-trujillo.gob.pe',
                ], 'UsuarioId');
            }

            $vinculo = ['UsuarioId' => $usuarioId, 'RolId' => $roles[$rol]];
            if (! DB::table('Seguridad.UsuarioRol')->where($vinculo)->exists()) {
                DB::table('Seguridad.UsuarioRol')->insert($vinculo + ['UsuarioRolFechaInicio' => '2026-01-01']);
            }

            $alcance = match ($ambito) {
                'global' => ['MicroredId' => null, 'EessId' => null],
                'microred' => $microred !== null ? ['MicroredId' => $microred, 'EessId' => null] : null,
                'eess' => $eess !== null ? ['MicroredId' => null, 'EessId' => $eess] : null,
                default => null,
            };
            if ($alcance !== null && ! DB::table('Seguridad.UsuarioAmbito')->where('UsuarioId', $usuarioId)->exists()) {
                DB::table('Seguridad.UsuarioAmbito')->insert(['UsuarioId' => $usuarioId] + $alcance);
            }
        }
    }

    /**
     * El usuario de demostracion "trabajador" es una persona real del establecimiento (con vinculo vigente, turnos y
     * asistencia de los datos de prueba): asi su inicio y su asistencia muestran algo. Null = no hay datos de prueba.
     */
    private function trabajadorConDatos(?int $eess): ?int
    {
        if ($eess === null) {
            return null;
        }

        return DB::table('Personal.VinculoLaboral as v')
            ->where('v.EessId', $eess)
            ->where('v.VinculoLaboralEstado', 1)
            ->whereNull('v.VinculoLaboralFechaFin')
            ->whereExists(fn ($q) => $q->from('Asistencia.AsistenciaDiaria as a')->whereColumn('a.VinculoLaboralId', 'v.VinculoLaboralId'))
            ->whereNotExists(fn ($q) => $q->from('Seguridad.Usuario as u')->whereColumn('u.TrabajadorId', 'v.TrabajadorId'))
            ->orderBy('v.VinculoLaboralId')
            ->value('v.TrabajadorId');
    }

    private function clave(): string
    {
        $definida = env('USUARIOS_PASSWORD');
        if ($definida) {
            return (string) $definida;
        }
        if (! app()->isProduction()) {
            return 'Prueba2026*';
        }
        $generada = Str::password(16, symbols: false);
        $this->command?->warn("Contraseña generada para los usuarios predeterminados (anótala, no se vuelve a mostrar): {$generada}");

        return $generada;
    }
}
