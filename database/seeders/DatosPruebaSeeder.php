<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba para recorrer TODOS los modulos de Nivel 0 y sus relaciones
 * (Microred -> EESS -> Dispositivo, Rol <-> Permiso, etc.). Se suma a lo que siembra
 * CatalogosSeeder; nunca debe correr en produccion (DatabaseSeeder ya lo impide).
 *
 * Los volumenes son deliberados: hay modulos con MUCHOS registros (microredes, permisos,
 * calendario, periodos: vista de tabla) y otros con POCOS (jornadas, metodos: vista de
 * tarjetas), y en cada modulo con baja logica hay registros inactivos.
 *
 * Todo es deterministico (fechas fijas, sin aleatorios): dos corridas dan el mismo estado.
 * Nombres de EESS, telefonos y documentos son FICTICIOS.
 */
class DatosPruebaSeeder extends Seeder
{
    use SiembraCatalogos;

    public function run(): void
    {
        $this->microredes();
        $this->establecimientos();
        $this->tablasDeTolerancia();
        $this->dispositivos();
        $this->calendarioNoLaborable();
        $this->periodosDeAsistencia();
        $this->permisosYRoles();
        $this->parámetrosDelSistema();
        $this->documentosLogsYAuditoria();
    }

    private function microredes(): void
    {
        // Completa los datos de las cuatro microredes base (ubigeo INEI de La Libertad).
        $complemento = [
            'MR-LE' => ['130105', '990100101', 'Av. Mansiche 450, La Esperanza'],
            'MR-EP' => ['130102', '990100102', 'Av. Hermanos Uceda 120, El Porvenir'],
            'MR-FM' => ['130103', '990100103', 'Jr. Amazonas 310, Florencia de Mora'],
            'MR-SEDE' => ['130101', '990100100', 'Jr. Bolívar 725, Trujillo'],
        ];
        foreach ($complemento as $codigo => [$ubigeo, $telefono, $direccion]) {
            DB::table('Organizacion.Microred')->where('MicroredCodigo', $codigo)->update([
                'MicroredUbigeo' => $ubigeo, 'MicroredTelefono' => $telefono, 'MicroredDireccion' => $direccion,
            ]);
        }

        $this->sembrar('Organizacion.Microred', 'MicroredCodigo', [
            ['MicroredCodigo' => 'MR-HU', 'MicroredNombre' => 'Microred Huanchaco', 'MicroredDistrito' => 'Huanchaco', 'MicroredUbigeo' => '130104', 'MicroredTelefono' => '990100104', 'MicroredDireccion' => 'Av. Larco 880, Huanchaco', 'MicroredDescripcion' => 'Zona costera norte'],
            ['MicroredCodigo' => 'MR-VL', 'MicroredNombre' => 'Microred Víctor Larco', 'MicroredDistrito' => 'Víctor Larco Herrera', 'MicroredUbigeo' => '130111', 'MicroredTelefono' => '990100111', 'MicroredDireccion' => 'Av. Víctor Larco 1200', 'MicroredDescripcion' => null],
            ['MicroredCodigo' => 'MR-LA', 'MicroredNombre' => 'Microred Laredo', 'MicroredDistrito' => 'Laredo', 'MicroredUbigeo' => '130106', 'MicroredTelefono' => '990100106', 'MicroredDireccion' => 'Plaza de Armas s/n, Laredo', 'MicroredDescripcion' => null],
            ['MicroredCodigo' => 'MR-MO', 'MicroredNombre' => 'Microred Moche', 'MicroredDistrito' => 'Moche', 'MicroredUbigeo' => '130107', 'MicroredTelefono' => '990100107', 'MicroredDireccion' => 'Av. Bolognesi 215, Moche', 'MicroredDescripcion' => null],
            ['MicroredCodigo' => 'MR-SA', 'MicroredNombre' => 'Microred Salaverry', 'MicroredDistrito' => 'Salaverry', 'MicroredUbigeo' => '130109', 'MicroredTelefono' => '990100109', 'MicroredDireccion' => 'Malecón Grau 40, Salaverry', 'MicroredDescripcion' => 'Zona portuaria'],
            // Inactiva: permite probar el filtro de estado y la reactivacion.
            ['MicroredCodigo' => 'MR-PO', 'MicroredNombre' => 'Microred Poroto (en reorganización)', 'MicroredDistrito' => 'Poroto', 'MicroredUbigeo' => '130108', 'MicroredTelefono' => null, 'MicroredDireccion' => null, 'MicroredDescripcion' => 'Unificada con otra microred', 'MicroredEstado' => 0],
        ]);
    }

    private function establecimientos(): void
    {
        $microredes = $this->ids('Organizacion.Microred', 'MicroredCodigo', 'MicroredId');
        $tipos = $this->ids('Organizacion.TipoEstablecimiento', 'TipoEstablecimientoCodigo', 'TipoEstablecimientoId');

        // [microred, tipo, codigo, nombre, categoria, estado]
        $eess = [
            ['MR-LE', 'PS', 'EESS-LE-02', 'P.S. Jerusalén', 'I-2', 1],
            ['MR-LE', 'PS', 'EESS-LE-03', 'P.S. Wichanzao', 'I-1', 1],
            ['MR-EP', 'CS', 'EESS-EP-02', 'C.S. Alto Trujillo', 'I-3', 1],
            ['MR-EP', 'PS', 'EESS-EP-03', 'P.S. Santa Verónica', 'I-1', 1],
            ['MR-FM', 'PS', 'EESS-FM-02', 'P.S. Buenos Aires', 'I-2', 1],
            ['MR-HU', 'CS', 'EESS-HU-01', 'C.S. Huanchaco', 'I-3', 1],
            ['MR-VL', 'CS', 'EESS-VL-01', 'C.S. Víctor Larco', 'I-4', 1],
            ['MR-VL', 'PS', 'EESS-VL-02', 'P.S. Las Delicias', 'I-1', 1],
            ['MR-LA', 'CS', 'EESS-LA-01', 'C.S. Laredo', 'I-3', 1],
            ['MR-MO', 'CS', 'EESS-MO-01', 'C.S. Moche', 'I-3', 1],
            ['MR-SA', 'PS', 'EESS-SA-01', 'P.S. Salaverry', 'I-2', 1],
            ['MR-SEDE', 'CSMC', 'SEDE-CSMC', 'Centro de Salud Mental Comunitario', null, 1],
            ['MR-PO', 'PS', 'EESS-PO-01', 'P.S. Poroto', 'I-1', 0],
        ];
        $this->sembrar('Organizacion.EstablecimientoSalud', 'EessCodigo', array_map(fn (array $e) => [
            'MicroredId' => $microredes[$e[0]],
            'TipoEstablecimientoId' => $tipos[$e[1]],
            'EessCodigo' => $e[2],
            'EessNombre' => $e[3],
            'EessCategoria' => $e[4],
            'EessEstado' => $e[5],
        ], $eess));
    }

    private function tablasDeTolerancia(): void
    {
        $this->sembrar('Configuracion.TablaTolerancia', 'TablaToleranciaCodigo', [
            ['TablaToleranciaCodigo' => 'RIT_TARDE', 'TablaToleranciaNombre' => 'Escala turno tarde (provisional)', 'TablaToleranciaDescripcion' => 'PROVISIONAL: pendiente de transcribir el Art. 22 del RIT para turno tarde'],
            ['TablaToleranciaCodigo' => 'RIT_GUARDIA', 'TablaToleranciaNombre' => 'Escala de guardias (provisional)', 'TablaToleranciaDescripcion' => 'PROVISIONAL: pendiente de transcribir el Art. 22 del RIT para guardias', 'TablaToleranciaEstado' => 0],
        ]);
    }

    private function dispositivos(): void
    {
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');

        // [eess|null, codigo, nombre, tipo, ubicacion, ip, estado]
        $dispositivos = [
            ['SEDE-RRHH', 'DISP-SEDE-01', 'Reloj facial - Sede RRHH', 'Facial', 'Ingreso principal de la sede', '192.168.10.11', 1],
            ['EESS-LE-01', 'DISP-LE-01', 'Lector de huella - C.S. La Esperanza', 'Huella dactilar', 'Recepción', '192.168.20.11', 1],
            ['EESS-LE-01', 'DISP-LE-02', 'Lector facial - Emergencia La Esperanza', 'Facial', 'Área de emergencia', '192.168.20.12', 1],
            ['EESS-EP-01', 'DISP-EP-01', 'Lector de huella - C.S. El Porvenir', 'Huella dactilar', 'Ingreso de personal', '192.168.30.11', 1],
            ['EESS-FM-01', 'DISP-FM-01', 'Lector de tarjeta - C.S. Florencia de Mora', 'Tarjeta de proximidad', 'Ingreso de personal', '192.168.40.11', 1],
            [null, 'DISP-MOV-01', 'Terminal móvil de campañas', 'Aplicativo móvil', 'Actividades extramurales', null, 1],
            ['EESS-EP-01', 'DISP-EP-99', 'Lector antiguo El Porvenir (retirado)', 'Huella dactilar', 'Almacén', null, 0],
        ];
        $this->sembrar('Biometria.DispositivoMarcacion', 'DispositivoMarcacionCodigo', array_map(fn (array $d) => [
            'EessId' => $d[0] === null ? null : $eess[$d[0]],
            'DispositivoMarcacionCodigo' => $d[1],
            'DispositivoMarcacionNombre' => $d[2],
            'DispositivoMarcacionTipo' => $d[3],
            'DispositivoMarcacionUbicacion' => $d[4],
            'DispositivoMarcacionIp' => $d[5],
            'DispositivoMarcacionEstado' => $d[6],
        ], $dispositivos));
    }

    private function calendarioNoLaborable(): void
    {
        $microredes = $this->ids('Organizacion.Microred', 'MicroredCodigo', 'MicroredId');

        // [fecha, tipo, descripcion, compensable, microred|null (toda la Red)]
        $dias = [
            ['2026-01-01', 'FERIADO', 'Año Nuevo', 0, null],
            ['2026-04-02', 'FERIADO', 'Jueves Santo', 0, null],
            ['2026-04-03', 'FERIADO', 'Viernes Santo', 0, null],
            ['2026-05-01', 'FERIADO', 'Día del Trabajo', 0, null],
            ['2026-06-07', 'FERIADO', 'Batalla de Arica y Día de la Bandera', 0, null],
            ['2026-06-29', 'FERIADO', 'San Pedro y San Pablo', 0, null],
            ['2026-07-23', 'FERIADO', 'Día de la Fuerza Aérea del Perú', 0, null],
            ['2026-07-28', 'FERIADO', 'Fiestas Patrias', 0, null],
            ['2026-07-29', 'FERIADO', 'Fiestas Patrias', 0, null],
            ['2026-08-06', 'FERIADO', 'Batalla de Junín', 0, null],
            ['2026-08-30', 'FERIADO', 'Santa Rosa de Lima', 0, null],
            ['2026-10-08', 'FERIADO', 'Combate de Angamos', 0, null],
            ['2026-11-01', 'FERIADO', 'Todos los Santos', 0, null],
            ['2026-12-08', 'FERIADO', 'Inmaculada Concepción', 0, null],
            ['2026-12-09', 'FERIADO', 'Batalla de Ayacucho', 0, null],
            ['2026-12-25', 'FERIADO', 'Navidad', 0, null],
            // Alcance por microred (datos de prueba).
            ['2026-10-16', 'DIA_NO_LABORABLE', 'Aniversario distrital (prueba)', 1, 'MR-LE'],
            ['2026-11-13', 'ASUETO', 'Jornada de confraternidad (prueba)', 1, 'MR-EP'],
            ['2026-09-18', 'DUELO', 'Duelo institucional (prueba)', 0, 'MR-FM'],
            // El mismo dia puede existir para toda la Red y para una microred concreta.
            ['2026-12-25', 'DIA_NO_LABORABLE', 'Navidad - extensión para la sede (prueba)', 0, 'MR-SEDE'],
        ];

        foreach ($dias as [$fecha, $tipo, $descripcion, $compensable, $microred]) {
            $microredId = $microred === null ? null : $microredes[$microred];
            $existe = DB::table('Soporte.CalendarioNoLaborable')
                ->where('CalendarioNoLaborableFecha', $fecha)
                ->when($microredId === null, fn ($q) => $q->whereNull('MicroredId'), fn ($q) => $q->where('MicroredId', $microredId))
                ->exists();
            if (! $existe) {
                DB::table('Soporte.CalendarioNoLaborable')->insert([
                    'MicroredId' => $microredId,
                    'CalendarioNoLaborableFecha' => $fecha,
                    'CalendarioNoLaborableTipo' => $tipo,
                    'CalendarioNoLaborableDescripcion' => $descripcion,
                    'CalendarioNoLaborableCompensable' => $compensable,
                ]);
            }
        }
    }

    private function periodosDeAsistencia(): void
    {
        foreach (range(1, 12) as $mes) {
            $inicio = CarbonImmutable::create(2026, $mes, 1);
            $fin = $inicio->endOfMonth();
            $estado = match (true) {
                $mes <= 8 => 'CERRADO',
                $mes === 9 => 'EN_PROCESO',
                default => 'ABIERTO',
            };

            if (DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => $mes])->exists()) {
                continue;
            }

            DB::table('Consolidacion.PeriodoAsistencia')->insert([
                'PeriodoAsistenciaAnio' => 2026,
                'PeriodoAsistenciaMes' => $mes,
                'PeriodoAsistenciaFechaInicio' => $inicio->toDateString(),
                'PeriodoAsistenciaFechaFin' => $fin->toDateString(),
                'PeriodoAsistenciaFechaCierre' => $estado === 'CERRADO' ? $fin->addDays(5)->setTime(10, 0)->toDateTimeString() : null,
                'PeriodoAsistenciaEstado' => $estado,
            ]);
        }
    }

    private function permisosYRoles(): void
    {
        $modulos = [
            'ORGANIZACION' => 'organizacion',
            'PERSONAL' => 'personal',
            'ASISTENCIA' => 'asistencia',
            'PROGRAMACION' => 'programacion',
            'SOLICITUDES' => 'solicitudes',
            'CONFIGURACION' => 'configuracion',
            'SEGURIDAD' => 'seguridad',
            'REPORTES' => 'reportes',
        ];

        $permisos = [];
        foreach ($modulos as $modulo => $nombre) {
            $permisos[] = ["{$modulo}_VER", "Ver {$nombre}", $modulo, "Consultar el módulo de {$nombre}", 1];
            $permisos[] = ["{$modulo}_EDITAR", "Editar {$nombre}", $modulo, "Crear y modificar datos del módulo de {$nombre}", 1];
        }
        $permisos[] = ['ASISTENCIA_JUSTIFICAR', 'Justificar faltas', 'ASISTENCIA', 'Registrar justificaciones de inasistencia', 1];
        $permisos[] = ['ASISTENCIA_APROBAR', 'Aprobar asistencia', 'ASISTENCIA', 'Validar marcaciones y justificaciones', 1];
        $permisos[] = ['SOLICITUDES_APROBAR', 'Aprobar solicitudes', 'SOLICITUDES', 'Aprobar papeletas, licencias y descansos', 1];
        $permisos[] = ['REPORTES_EXPORTAR', 'Exportar reportes', 'REPORTES', 'Descargar reportes en Excel y PDF', 1];
        $permisos[] = ['SEGURIDAD_AUDITORIA', 'Ver auditoría', 'SEGURIDAD', 'Consultar el registro de auditoría', 1];
        $permisos[] = ['SISTEMA_PARAMETROS', 'Editar parámetros del sistema', 'CONFIGURACION', 'Modificar los parámetros globales', 1];
        $permisos[] = ['LEGADO_IMPORTAR', 'Importar datos heredados', null, 'Permiso retirado (prueba de baja logica)', 0];

        $this->sembrar('Seguridad.Permiso', 'PermisoCodigo', array_map(fn (array $p) => [
            'PermisoCodigo' => $p[0],
            'PermisoNombre' => $p[1],
            'PermisoModulo' => $p[2],
            'PermisoDescripcion' => $p[3],
            'PermisoEstado' => $p[4],
        ], $permisos));

        // Rol <-> Permiso (Nivel 1): que permisos tiene cada rol.
        $roles = $this->ids('Seguridad.Rol', 'RolCodigo', 'RolId');
        $todos = $this->ids('Seguridad.Permiso', 'PermisoCodigo', 'PermisoId');
        unset($todos['LEGADO_IMPORTAR']);

        $porRol = [
            'ADMIN' => array_keys($todos),
            'RRHH_RED' => array_values(array_filter(array_keys($todos), fn ($c) => ! str_starts_with($c, 'SEGURIDAD') && $c !== 'SISTEMA_PARAMETROS')),
            'JEFE_MICRORED' => ['ORGANIZACION_VER', 'PERSONAL_VER', 'ASISTENCIA_VER', 'ASISTENCIA_APROBAR', 'SOLICITUDES_VER', 'SOLICITUDES_APROBAR', 'PROGRAMACION_VER', 'REPORTES_VER', 'REPORTES_EXPORTAR'],
            'RESP_EESS' => ['PERSONAL_VER', 'ASISTENCIA_VER', 'ASISTENCIA_JUSTIFICAR', 'SOLICITUDES_VER', 'SOLICITUDES_APROBAR', 'PROGRAMACION_VER'],
            'PROGRAMADOR' => ['PROGRAMACION_VER', 'PROGRAMACION_EDITAR', 'PERSONAL_VER'],
            'TRABAJADOR' => ['ASISTENCIA_VER', 'SOLICITUDES_VER'],
            'PORTERIA' => ['SOLICITUDES_VER', 'SOLICITUDES_EDITAR'],
        ];
        foreach ($porRol as $rol => $codigos) {
            foreach ($codigos as $codigo) {
                $fila = ['RolId' => $roles[$rol], 'PermisoId' => $todos[$codigo]];
                if (! DB::table('Seguridad.RolPermiso')->where($fila)->exists()) {
                    DB::table('Seguridad.RolPermiso')->insert($fila);
                }
            }
        }
    }

    private function parámetrosDelSistema(): void
    {
        $this->sembrar('Configuracion.ParametroSistema', 'ParametroSistemaCodigo', [
            ['ParametroSistemaCodigo' => 'MAX_ADJUNTO_MB', 'ParametroSistemaValor' => '5', 'ParametroSistemaDescripcion' => 'Tamaño máximo de un documento de sustento, en MB'],
            ['ParametroSistemaCodigo' => 'ZONA_HORARIA', 'ParametroSistemaValor' => 'America/Lima', 'ParametroSistemaDescripcion' => 'Zona horaria de las marcaciones'],
            ['ParametroSistemaCodigo' => 'DIAS_CIERRE_PERIODO', 'ParametroSistemaValor' => '5', 'ParametroSistemaDescripcion' => 'Días tras el fin de mes para cerrar el periodo de asistencia'],
            ['ParametroSistemaCodigo' => 'FORMATO_FECHA', 'ParametroSistemaValor' => 'dd/MM/yyyy', 'ParametroSistemaDescripcion' => 'Formato de fecha para reportes'],
            ['ParametroSistemaCodigo' => 'MODO_IMPORTACION_LEGADO', 'ParametroSistemaValor' => '1', 'ParametroSistemaDescripcion' => 'Parametro retirado (prueba de baja logica)', 'ParametroSistemaEstado' => 0],
        ]);
    }

    private function documentosLogsYAuditoria(): void
    {
        $documentos = [
            ['certificado-medico-0001.pdf', 'application/pdf', 'pdf', 184320, '2026-09-01 09:15:00'],
            ['citt-0002.pdf', 'application/pdf', 'pdf', 96256, '2026-09-02 10:40:00'],
            ['resolucion-comision-0003.pdf', 'application/pdf', 'pdf', 152600, '2026-09-03 11:05:00'],
            ['constancia-capacitación-0004.pdf', 'application/pdf', 'pdf', 210944, '2026-09-08 08:30:00'],
            ['foto-constatación-0005.jpg', 'image/jpeg', 'jpg', 812450, '2026-09-10 16:20:00'],
            ['acta-duelo-0006.pdf', 'application/pdf', 'pdf', 73728, '2026-09-18 14:00:00'],
        ];
        $this->sembrarSiVacia('Soporte.DocumentoSustento', array_map(fn (array $d) => [
            'DocumentoSustentoNombre' => $d[0],
            'DocumentoSustentoRuta' => '/storage/documentos/2026/09/'.$d[0],
            'DocumentoSustentoTipo' => $d[1],
            'DocumentoSustentoExtension' => $d[2],
            'DocumentoSustentoTamanoBytes' => $d[3],
            'DocumentoSustentoHash' => hash('sha256', $d[0]),
            'DocumentoSustentoFechaRegistro' => $d[4],
        ], $documentos));

        // [sistema, operacion, direccion, resumen, resultado, error, fecha, reintentos]
        $logs = [
            ['RELOJ_BIOMETRICO', 'SYNC_MARCACIONES', 'ENTRADA', '120 marcaciones recibidas de DISP-LE-01', 'OK', null, '2026-09-28 06:00:05', 0],
            ['RELOJ_BIOMETRICO', 'SYNC_MARCACIONES', 'ENTRADA', '0 marcaciones: dispositivo sin respuesta', 'ERROR', 'Tiempo de espera agotado al conectar con 192.168.30.11', '2026-09-28 06:00:35', 3],
            ['RELOJ_BIOMETRICO', 'SYNC_MARCACIONES', 'ENTRADA', '98 marcaciones recibidas de DISP-EP-01', 'OK', null, '2026-09-28 06:05:10', 1],
            ['AIRHSP', 'CONSULTA_PLAZA', 'SALIDA', 'Consulta de plaza por codigo AIRHSP', 'OK', null, '2026-09-29 09:12:44', 0],
            ['RENIEC', 'VALIDAR_DNI', 'SALIDA', 'Validación de DNI 00000000', 'ERROR', 'Servicio no disponible (503)', '2026-09-29 09:30:02', 2],
        ];
        $this->sembrarSiVacia('Soporte.LogIntegracion', array_map(fn (array $l) => [
            'LogIntegracionSistemaExterno' => $l[0],
            'LogIntegracionOperacion' => $l[1],
            'LogIntegracionDireccion' => $l[2],
            'LogIntegracionPayloadResumen' => $l[3],
            'LogIntegracionResultado' => $l[4],
            'LogIntegracionMensajeError' => $l[5],
            'LogIntegracionFechaHora' => $l[6],
            'LogIntegracionReintentos' => $l[7],
        ], $logs));

        // Historial de ejemplo (sin usuario: aun no hay login, UsuarioId es NULL).
        $auditoría = [
            ['Organizacion', 'Microred', 'INSERT', '5', null, '{"MicroredCodigo":"MR-HU","MicroredNombre":"Microred Huanchaco"}', '2026-09-20 10:01:00'],
            ['Organizacion', 'Microred', 'UPDATE', '5', '{"MicroredTelefono":null}', '{"MicroredTelefono":"990100104"}', '2026-09-20 10:05:00'],
            ['Configuracion', 'ParametroSistema', 'UPDATE', '3', '{"ParametroSistemaValor":"2"}', '{"ParametroSistemaValor":"3"}', '2026-09-21 15:30:00'],
            ['Soporte', 'CalendarioNoLaborable', 'INSERT', '17', null, '{"CalendarioNoLaborableFecha":"2026-10-16","CalendarioNoLaborableTipo":"DIA_NO_LABORABLE"}', '2026-09-22 09:00:00'],
            ['Biometria', 'DispositivoMarcacion', 'UPDATE', '7', '{"DispositivoMarcacionEstado":true}', '{"DispositivoMarcacionEstado":false}', '2026-09-25 17:45:00'],
        ];
        $this->sembrarSiVacia('Seguridad.Auditoria', array_map(fn (array $a) => [
            'AuditoriaEsquema' => $a[0],
            'AuditoriaTabla' => $a[1],
            'AuditoriaOperacion' => $a[2],
            'AuditoriaRegistroId' => $a[3],
            'AuditoriaDatosAnteriores' => $a[4],
            'AuditoriaDatosNuevos' => $a[5],
            'AuditoriaFechaHora' => $a[6],
            'AuditoriaDireccionIp' => '192.168.10.50',
        ], $auditoría));
    }

    /**
     * Para tablas sin clave natural (documentos, logs, auditoría): inserta solo si estan vacias,
     * de modo que correr el seeder dos veces no duplique filas.
     *
     * @param  list<array<string,mixed>>  $filas
     */
    private function sembrarSiVacia(string $tabla, array $filas): void
    {
        if (DB::table($tabla)->exists()) {
            return;
        }
        foreach ($filas as $fila) {
            DB::table($tabla)->insert($fila);
        }
    }
}
