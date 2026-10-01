<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba para recorrer TODOS los modulos de Nivel 0 y sus relaciones
 * (Microred -> EESS -> Dispositivo, Trabajador, Cargo, Horario -> Turno, Rol <-> Permiso, etc.). Se suma a lo que siembra
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
        $this->escalaDeEjemplo();
        $this->dispositivos();
        $this->cargos();
        $this->trabajadores();
        $this->horarios();
        $this->parametrosDeJornada();
        $this->motivosDePapeleta();
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

    private function escalaDeEjemplo(): void
    {
        // La escala real del RIT es una sola (RIT_GENERAL, en CatalogosSeeder). Esta queda VACIA a proposito:
        // sirve para probar el alta de tramos desde cero sin chocar con los de la escala oficial.
        $this->sembrar('Configuracion.TablaTolerancia', 'TablaToleranciaCodigo', [
            ['TablaToleranciaCodigo' => 'ESCALA_PRUEBA', 'TablaToleranciaNombre' => 'Escala de ejemplo (prueba)', 'TablaToleranciaDescripcion' => 'Sin tramos: para probar el alta de tramos'],
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

    private function cargos(): void
    {
        $grupos = $this->ids('Personal.GrupoOcupacional', 'GrupoOcupacionalCodigo', 'GrupoOcupacionalId');

        // [grupo, codigo|null, nombre, es jefatura, estado]
        $cargos = [
            ['FUNCIONARIO', 'CG-001', 'Director(a) Ejecutivo(a) de Red', 1, 1],
            ['FUNCIONARIO', 'CG-002', 'Jefe(a) de Microred', 1, 1],
            ['FUNCIONARIO', 'CG-003', 'Jefe(a) de Establecimiento de Salud', 1, 1],
            ['PROFESIONAL', 'CG-010', 'Médico(a) Cirujano(a)', 0, 1],
            ['PROFESIONAL', 'CG-011', 'Enfermero(a)', 0, 1],
            ['PROFESIONAL', 'CG-012', 'Obstetra', 0, 1],
            ['PROFESIONAL', 'CG-013', 'Odontólogo(a)', 0, 1],
            ['PROFESIONAL', 'CG-014', 'Psicólogo(a)', 0, 1],
            ['PROFESIONAL', 'CG-015', 'Nutricionista', 0, 1],
            ['PROFESIONAL', 'CG-016', 'Químico(a) Farmacéutico(a)', 0, 1],
            ['PROFESIONAL', 'CG-017', 'Tecnólogo(a) Médico(a)', 0, 1],
            ['PROF_ADM', 'CG-020', 'Contador(a)', 0, 1],
            ['PROF_ADM', 'CG-021', 'Abogado(a)', 0, 1],
            ['PROF_ADM', 'CG-022', 'Analista de Recursos Humanos', 0, 1],
            ['PROF_ADM', 'CG-023', 'Responsable de Control de Asistencia y Permanencia', 1, 1],
            ['TECNICO', 'CG-030', 'Técnico(a) en Enfermería', 0, 1],
            ['TECNICO', 'CG-031', 'Técnico(a) Administrativo(a)', 0, 1],
            ['TECNICO', 'CG-032', 'Técnico(a) de Laboratorio', 0, 1],
            ['AUXILIAR', 'CG-040', 'Auxiliar Administrativo(a)', 0, 1],
            ['AUXILIAR', null, 'Vigilante de Portería', 0, 1],
            ['ASISTENCIAL', null, 'Personal asistencial de apoyo', 0, 1],
            ['TECNICO', 'CG-099', 'Cargo en desuso (prueba)', 0, 0],
        ];
        $this->sembrar('Personal.Cargo', 'CargoNombre', array_map(fn (array $c) => [
            'GrupoOcupacionalId' => $grupos[$c[0]],
            'CargoCodigo' => $c[1],
            'CargoNombre' => $c[2],
            'CargoEsJefatura' => $c[3],
            'CargoEstado' => $c[4],
        ], $cargos));
    }

    private function trabajadores(): void
    {
        $tipos = $this->ids('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadId');
        $profesiones = $this->ids('Personal.Profesion', 'ProfesionCodigo', 'ProfesionId');

        // Personas FICTICIAS. Los documentos 700000NN no corresponden a nadie.
        // [tipo, numero, nombres, ap. paterno, ap. materno, sexo, nacimiento, profesion|null, correo|null, telefono|null, estado]
        $personas = [
            ['DNI', '70000001', 'María Elena', 'Quispe', 'Huamán', 'F', '1985-03-14', 'ENFERMERIA', 'maria.quispe@ejemplo.pe', '991000001', 1],
            ['DNI', '70000002', 'Carlos Alberto', 'Rojas', 'Vásquez', 'M', '1979-11-02', 'MEDICO', 'carlos.rojas@ejemplo.pe', '991000002', 1],
            ['DNI', '70000003', 'Lucía Fernanda', 'Castillo', 'Díaz', 'F', '1990-07-21', 'OBSTETRICIA', 'lucia.castillo@ejemplo.pe', '991000003', 1],
            ['DNI', '70000004', 'José Luis', 'Paredes', null, 'M', '1982-01-30', 'ODONTOLOGIA', null, '991000004', 1],
            ['DNI', '70000005', 'Rosa Amelia', 'Vargas', 'Salazar', 'F', '1975-09-09', 'PSICOLOGIA', 'rosa.vargas@ejemplo.pe', null, 1],
            ['DNI', '70000006', 'Miguel Ángel', 'Torres', 'León', 'M', '1988-05-17', 'TEC_ENF', null, '991000006', 1],
            ['DNI', '70000007', 'Ana Patricia', 'Mendoza', 'Ruiz', 'F', '1993-12-25', 'NUTRICION', 'ana.mendoza@ejemplo.pe', '991000007', 1],
            ['DNI', '70000008', 'Pedro Pablo', 'Gutiérrez', 'Arce', 'M', '1970-04-03', 'CONTABILIDAD', 'pedro.gutierrez@ejemplo.pe', '991000008', 1],
            ['DNI', '70000009', 'Jenny Karina', 'Alva', 'Chávez', 'F', '1995-08-12', 'FARMACIA', 'jenny.alva@ejemplo.pe', '991000009', 1],
            ['DNI', '70000010', 'Víctor Hugo', 'Sánchez', 'Mori', 'M', '1968-06-28', 'SIN_PROF', null, null, 1],
            ['DNI', '70000011', 'Gloria Isabel', "D'Angelo", 'Pérez', 'F', '1984-10-05', 'TRABSOCIAL', 'gloria.dangelo@ejemplo.pe', '991000011', 1],
            ['DNI', '70000012', 'Luis Fernando', 'Cruz', 'Neyra', 'M', '1991-02-19', 'TEC_LAB', null, '991000012', 1],
            ['CE', '001234567', 'Andrés Felipe', 'Moreno', 'Castro', 'M', '1987-03-08', 'MEDICO', 'andres.moreno@ejemplo.pe', '991000013', 1],
            ['PAS', 'AB123456', 'Elena', 'Petrova', null, 'F', '1989-09-30', 'ENFERMERIA', null, null, 1],
            // Dado de baja: permite probar el filtro de estado y la reactivacion.
            ['DNI', '70000099', 'Juan Carlos', 'Retirado', 'Prueba', 'M', '1972-12-12', null, null, null, 0],
        ];

        foreach ($personas as [$tipo, $numero, $nombres, $paterno, $materno, $sexo, $nacimiento, $profesion, $correo, $telefono, $estado]) {
            if (DB::table('Personal.Trabajador')->where(['TipoDocumentoIdentidadId' => $tipos[$tipo], 'TrabajadorNumeroDocumento' => $numero])->exists()) {
                continue;
            }
            DB::table('Personal.Trabajador')->insert([
                'TipoDocumentoIdentidadId' => $tipos[$tipo],
                'ProfesionId' => $profesion === null ? null : $profesiones[$profesion],
                'TrabajadorNumeroDocumento' => $numero,
                'TrabajadorNombres' => $nombres,
                'TrabajadorApellidoPaterno' => $paterno,
                'TrabajadorApellidoMaterno' => $materno,
                'TrabajadorSexo' => $sexo,
                'TrabajadorFechaNacimiento' => $nacimiento,
                'TrabajadorCorreo' => $correo,
                'TrabajadorTelefono' => $telefono,
                'TrabajadorDireccion' => 'Av. Prueba '.substr($numero, -2).', Trujillo',
                'TrabajadorFechaRegistro' => '2026-09-01 08:00:00',
                'TrabajadorEstado' => $estado,
            ]);
        }
    }

    private function horarios(): void
    {
        $jornadas = $this->ids('Configuracion.TipoJornada', 'TipoJornadaCodigo', 'TipoJornadaId');
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');

        // [jornada, eess|null, codigo, nombre, descripcion, rotativo, estado]
        $horarios = [
            ['ASISTENC', null, 'HOR-ESS-M', 'Establecimientos - turno mañana (lun-sáb)', '07:30 a 13:30 de lunes a sábado (RIT Art. 16.2)', 0, 1],
            ['ASISTENC', null, 'HOR-ESS-T', 'Establecimientos - turno tarde (lun-sáb)', '13:30 a 19:30 de lunes a sábado (RIT Art. 16.2)', 0, 1],
            ['ASISTENC', 'EESS-LE-01', 'HOR-LE-ROT', 'C.S. La Esperanza - mañana y tarde alternados', 'Mañana lunes, miércoles y viernes; tarde martes, jueves y sábado', 1, 1],
            ['ADMIN', 'SEDE-CSMC', 'HOR-CSMC-ADM', 'Salud Mental Comunitaria - administrativo', '07:30 a 15:30 de lunes a viernes', 0, 1],
            ['ASISTENC', 'EESS-FM-01', 'HOR-FM-OLD', 'C.S. Florencia de Mora - horario anterior', 'Retirado (prueba de baja lógica)', 0, 0],
        ];
        $this->sembrar('Configuracion.Horario', 'HorarioCodigo', array_map(fn (array $h) => [
            'TipoJornadaId' => $jornadas[$h[0]],
            'EessId' => $h[1] === null ? null : $eess[$h[1]],
            'HorarioCodigo' => $h[2],
            'HorarioNombre' => $h[3],
            'HorarioDescripcion' => $h[4],
            'HorarioEsRotativo' => $h[5],
            'HorarioEstado' => $h[6],
        ], $horarios));

        $horarioIds = $this->ids('Configuracion.Horario', 'HorarioCodigo', 'HorarioId');
        $turnoIds = $this->ids('Configuracion.Turno', 'TurnoCodigo', 'TurnoId');

        // [horario, turno, dias ISO 1=lunes ... 7=domingo]
        $detalle = [
            ['HOR-ESS-M', 'M', [1, 2, 3, 4, 5, 6]],
            ['HOR-ESS-T', 'T', [1, 2, 3, 4, 5, 6]],
            ['HOR-LE-ROT', 'M', [1, 3, 5]],
            ['HOR-LE-ROT', 'T', [2, 4, 6]],
            ['HOR-CSMC-ADM', 'ADM-D', [1, 2, 3, 4, 5]],
        ];
        foreach ($detalle as [$horario, $turno, $dias]) {
            foreach ($dias as $dia) {
                $fila = ['HorarioId' => $horarioIds[$horario], 'TurnoId' => $turnoIds[$turno], 'HorarioDetalleDia' => $dia];
                if (! DB::table('Configuracion.HorarioDetalle')->where($fila)->exists()) {
                    DB::table('Configuracion.HorarioDetalle')->insert($fila);
                }
            }
        }
    }

    private function parametrosDeJornada(): void
    {
        $jornadas = $this->ids('Configuracion.TipoJornada', 'TipoJornadaCodigo', 'TipoJornadaId');

        // [jornada, desde, hasta|null, diarias, semanales, mensuales, estado]
        $parametros = [
            // Vigencia anterior de las guardias (cerrada antes de la vigente de CatalogosSeeder, que arranca en 2020).
            ['GUARDIA', '2015-01-01', '2019-12-31', 12, 36, 150, 1],
            // Propuesta futura sin aprobar: queda inactiva, asi que no se superpone con la vigente.
            ['ASISTENC', '2027-01-01', null, 6, 36, 150, 0],
        ];
        foreach ($parametros as [$jornada, $desde, $hasta, $diarias, $semanales, $mensuales, $estado]) {
            if (DB::table('Configuracion.ParametroJornada')->where(['TipoJornadaId' => $jornadas[$jornada], 'ParametroJornadaVigenciaDesde' => $desde])->exists()) {
                continue;
            }
            DB::table('Configuracion.ParametroJornada')->insert([
                'TipoJornadaId' => $jornadas[$jornada],
                'ParametroJornadaVigenciaDesde' => $desde,
                'ParametroJornadaVigenciaHasta' => $hasta,
                'ParametroJornadaHorasDiarias' => $diarias,
                'ParametroJornadaHorasSemanales' => $semanales,
                'ParametroJornadaHorasMensuales' => $mensuales,
                'ParametroJornadaEstado' => $estado,
            ]);
        }
    }

    private function motivosDePapeleta(): void
    {
        $tipos = $this->ids('Solicitudes.TipoPapeleta', 'TipoPapeletaCodigo', 'TipoPapeletaId');

        // [tipo, codigo, nombre, estado]
        $motivos = [
            ['COMISION', 'COM_SUPERVISION', 'Supervisión o monitoreo de establecimientos', 1],
            ['PERM_OFICIAL', 'OFI_REUNION', 'Reunión convocada por la Dirección', 1],
            ['PERM_SALUD', 'SAL_VACUNA', 'Vacunación del trabajador', 1],
            ['ESTUDIOS', 'EST_EXAMEN', 'Examen académico', 1],
            ['ESTUDIOS', 'EST_CLASE', 'Clases programadas', 1],
            ['LACTANCIA', 'LAC_ANTIGUO', 'Registro anterior de lactancia (retirado)', 0],
        ];
        $this->sembrar('Solicitudes.MotivoPapeleta', 'MotivoPapeletaCodigo', array_map(fn (array $m) => [
            'TipoPapeletaId' => $tipos[$m[0]],
            'MotivoPapeletaCodigo' => $m[1],
            'MotivoPapeletaNombre' => $m[2],
            'MotivoPapeletaEstado' => $m[3],
        ], $motivos));
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
