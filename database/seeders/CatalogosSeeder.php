<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogos base sin los cuales el sistema no arranca (roles, regimenes laborales,
 * tipos de documento, estados de asistencia, turnos base, escala de tolerancia...).
 * Antes vivian en la seccion 20 de V001__esquema_base.sql; el esquema ahora es solo
 * estructura y los datos se siembran aqui.
 *
 * Es IDEMPOTENTE: inserta solo lo que falta (por codigo) y no toca lo que ya existe,
 * asi que es seguro en cualquier ambiente, produccion incluida:
 *     php artisan db:seed --class=CatalogosSeeder
 *
 * Escala de tolerancia, turnos y horarios segun el Reglamento Interno de Trabajo (RIT):
 *   - Art. 16: sede administrativa de lunes a viernes 07:30-15:30 (refrigerio de 45 min fuera de la
 *     jornada); establecimientos de salud en turnos de 6 h: manana 07:30-13:30 y tarde 13:30-19:30;
 *     guardias de 12 h: diurna 07:30-19:30 y nocturna 19:30-07:30.
 *   - Art. 20: no se programan guardias de 24 horas continuas (por eso no hay turno G24).
 *   - Art. 22: 5 min de tolerancia; tardanza de 6-10 min descuenta 10, de 11-20 descuenta 20, de 21-30
 *     descuenta 30; desde el minuto 31 es inasistencia injustificada. Rige igual para la sede, los
 *     turnos manana y tarde y las guardias. Art. 23 b): salir antes de la hora sin autorizacion es
 *     inasistencia.
 */
class CatalogosSeeder extends Seeder
{
    use SiembraCatalogos;

    public function run(): void
    {
        $this->organizacion();
        $this->personal();
        $this->configuracion();
        $this->biometria();
        $this->programacion();
        $this->asistencia();
        $this->solicitudes();
        $this->compensaciones();
        $this->disciplina();
        $this->seguridad();
        $this->parametrosDelSistema();
    }

    private function organizacion(): void
    {
        $this->sembrar('Organizacion.TipoEstablecimiento', 'TipoEstablecimientoCodigo', [
            ['TipoEstablecimientoCodigo' => 'CS', 'TipoEstablecimientoNombre' => 'Centro de Salud', 'TipoEstablecimientoDescripcion' => 'Establecimiento con poblacion asignada y mayor capacidad resolutiva'],
            ['TipoEstablecimientoCodigo' => 'PS', 'TipoEstablecimientoNombre' => 'Puesto de Salud', 'TipoEstablecimientoDescripcion' => 'Establecimiento del primer nivel de atencion'],
            ['TipoEstablecimientoCodigo' => 'CSMC', 'TipoEstablecimientoNombre' => 'Centro de Salud Mental', 'TipoEstablecimientoDescripcion' => 'Centro de salud mental comunitaria'],
            ['TipoEstablecimientoCodigo' => 'HOSPITAL', 'TipoEstablecimientoNombre' => 'Hospital', 'TipoEstablecimientoDescripcion' => 'Establecimiento del segundo nivel de atencion'],
            ['TipoEstablecimientoCodigo' => 'UNIDAD_ADMIN', 'TipoEstablecimientoNombre' => 'Unidad Administrativa', 'TipoEstablecimientoDescripcion' => 'Oficina de la sede: RRHH, logistica, administracion'],
        ]);

        // Microredes (distritos) y establecimientos base. Ajustar a la conformacion vigente de la Red.
        $this->sembrar('Organizacion.Microred', 'MicroredCodigo', [
            ['MicroredCodigo' => 'MR-LE', 'MicroredNombre' => 'Microred La Esperanza', 'MicroredDistrito' => 'La Esperanza'],
            ['MicroredCodigo' => 'MR-EP', 'MicroredNombre' => 'Microred El Porvenir', 'MicroredDistrito' => 'El Porvenir'],
            ['MicroredCodigo' => 'MR-FM', 'MicroredNombre' => 'Microred Florencia de Mora', 'MicroredDistrito' => 'Florencia de Mora'],
            ['MicroredCodigo' => 'MR-SEDE', 'MicroredNombre' => 'Sede Administrativa', 'MicroredDistrito' => 'Trujillo'],
        ]);

        $microredes = $this->ids('Organizacion.Microred', 'MicroredCodigo', 'MicroredId');
        $tipos = $this->ids('Organizacion.TipoEstablecimiento', 'TipoEstablecimientoCodigo', 'TipoEstablecimientoId');

        $this->sembrar('Organizacion.EstablecimientoSalud', 'EessCodigo', [
            ['MicroredId' => $microredes['MR-LE'], 'TipoEstablecimientoId' => $tipos['CS'], 'EessCodigo' => 'EESS-LE-01', 'EessNombre' => 'C.S. La Esperanza', 'EessCategoria' => 'I-4'],
            ['MicroredId' => $microredes['MR-EP'], 'TipoEstablecimientoId' => $tipos['CS'], 'EessCodigo' => 'EESS-EP-01', 'EessNombre' => 'C.S. El Porvenir', 'EessCategoria' => 'I-4'],
            ['MicroredId' => $microredes['MR-FM'], 'TipoEstablecimientoId' => $tipos['CS'], 'EessCodigo' => 'EESS-FM-01', 'EessNombre' => 'C.S. Florencia de Mora', 'EessCategoria' => 'I-3'],
            ['MicroredId' => $microredes['MR-SEDE'], 'TipoEstablecimientoId' => $tipos['UNIDAD_ADMIN'], 'EessCodigo' => 'SEDE-RRHH', 'EessNombre' => 'Oficina de Recursos Humanos', 'EessCategoria' => null],
        ]);

        $this->sembrar('Organizacion.TipoResponsabilidad', 'TipoResponsabilidadCodigo', [
            ['TipoResponsabilidadCodigo' => 'JEFE_EESS', 'TipoResponsabilidadNombre' => 'Jefe del Establecimiento de Salud', 'TipoResponsabilidadDescripcion' => 'Maxima autoridad del EESS'],
            ['TipoResponsabilidadCodigo' => 'RESP_PERSONAL', 'TipoResponsabilidadNombre' => 'Responsable del Personal', 'TipoResponsabilidadDescripcion' => 'Responsable del control de personal y asistencia del EESS'],
            ['TipoResponsabilidadCodigo' => 'RESP_PROGRAMACION', 'TipoResponsabilidadNombre' => 'Responsable de Programacion', 'TipoResponsabilidadDescripcion' => 'Elabora y remite la programacion de turnos'],
            ['TipoResponsabilidadCodigo' => 'RESP_ASISTENCIA', 'TipoResponsabilidadNombre' => 'Responsable de Asistencia', 'TipoResponsabilidadDescripcion' => 'Valida marcaciones y justificaciones del EESS'],
            ['TipoResponsabilidadCodigo' => 'COORDINADOR', 'TipoResponsabilidadNombre' => 'Coordinador', 'TipoResponsabilidadDescripcion' => 'Coordinacion funcional'],
        ]);
    }

    private function personal(): void
    {
        $this->sembrar('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadCodigo', [
            ['TipoDocumentoIdentidadCodigo' => 'DNI', 'TipoDocumentoIdentidadNombre' => 'Documento Nacional de Identidad', 'TipoDocumentoIdentidadAbreviatura' => 'DNI', 'TipoDocumentoIdentidadLongitud' => 8],
            ['TipoDocumentoIdentidadCodigo' => 'CE', 'TipoDocumentoIdentidadNombre' => 'Carne de Extranjeria', 'TipoDocumentoIdentidadAbreviatura' => 'CE', 'TipoDocumentoIdentidadLongitud' => 12],
            ['TipoDocumentoIdentidadCodigo' => 'PAS', 'TipoDocumentoIdentidadNombre' => 'Pasaporte', 'TipoDocumentoIdentidadAbreviatura' => 'PAS', 'TipoDocumentoIdentidadLongitud' => 12],
            ['TipoDocumentoIdentidadCodigo' => 'PTP', 'TipoDocumentoIdentidadNombre' => 'Permiso Temporal de Permanencia', 'TipoDocumentoIdentidadAbreviatura' => 'PTP', 'TipoDocumentoIdentidadLongitud' => 12],
        ]);

        $this->sembrar('Personal.RegimenLaboral', 'RegimenLaboralCodigo', [
            ['RegimenLaboralCodigo' => 'DL276', 'RegimenLaboralNombre' => 'Regimen Publico - D.L. 276', 'RegimenLaboralBaseLegal' => 'Decreto Legislativo N 276'],
            ['RegimenLaboralCodigo' => 'DL728', 'RegimenLaboralNombre' => 'Regimen Privado - D.L. 728', 'RegimenLaboralBaseLegal' => 'Decreto Legislativo N 728'],
            ['RegimenLaboralCodigo' => 'DL1057', 'RegimenLaboralNombre' => 'Contrato Administrativo de Servicios', 'RegimenLaboralBaseLegal' => 'Decreto Legislativo N 1057 (CAS)'],
            ['RegimenLaboralCodigo' => 'DL1153', 'RegimenLaboralNombre' => 'Personal de la Salud - D.L. 1153', 'RegimenLaboralBaseLegal' => 'Decreto Legislativo N 1153'],
            ['RegimenLaboralCodigo' => 'SERVIR', 'RegimenLaboralNombre' => 'Servicio Civil - Ley 30057', 'RegimenLaboralBaseLegal' => 'Ley N 30057'],
            ['RegimenLaboralCodigo' => 'OTRO', 'RegimenLaboralNombre' => 'Otro regimen', 'RegimenLaboralBaseLegal' => null],
        ]);

        $this->sembrar('Personal.CondicionLaboral', 'CondicionLaboralCodigo', [
            ['CondicionLaboralCodigo' => 'NOMBRADO', 'CondicionLaboralNombre' => 'Nombrado', 'CondicionLaboralEsPermanente' => 1, 'CondicionLaboralRequiereAirhsp' => 1, 'CondicionLaboralDescripcion' => 'Servidor nombrado en plaza organica'],
            ['CondicionLaboralCodigo' => 'CONTRATADO', 'CondicionLaboralNombre' => 'Contratado', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 1, 'CondicionLaboralDescripcion' => 'Contratado a plazo determinado'],
            ['CondicionLaboralCodigo' => 'CAS', 'CondicionLaboralNombre' => 'CAS', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 1, 'CondicionLaboralDescripcion' => 'Contrato Administrativo de Servicios'],
            ['CondicionLaboralCodigo' => 'CAS_INDETERMINADO', 'CondicionLaboralNombre' => 'CAS Indeterminado', 'CondicionLaboralEsPermanente' => 1, 'CondicionLaboralRequiereAirhsp' => 1, 'CondicionLaboralDescripcion' => 'CAS a plazo indeterminado'],
            ['CondicionLaboralCodigo' => 'SERUMS_REM', 'CondicionLaboralNombre' => 'SERUMS Remunerado', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 1, 'CondicionLaboralDescripcion' => 'Servicio Rural y Urbano Marginal de Salud remunerado'],
            ['CondicionLaboralCodigo' => 'SERUMS_EQUIV', 'CondicionLaboralNombre' => 'SERUMS Equivalente', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => 'SERUMS equivalente, no remunerado por la entidad'],
            ['CondicionLaboralCodigo' => 'DESTACADO', 'CondicionLaboralNombre' => 'Destacado', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => 'Personal destacado desde otra entidad'],
            ['CondicionLaboralCodigo' => 'TERCEROS', 'CondicionLaboralNombre' => 'Locacion de Servicios', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => 'Prestacion de servicios por terceros'],
            ['CondicionLaboralCodigo' => 'INTERNO', 'CondicionLaboralNombre' => 'Interno / Practicante', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => 'Internado o practicas preprofesionales'],
            ['CondicionLaboralCodigo' => 'RESIDENTE', 'CondicionLaboralNombre' => 'Medico Residente', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => 'Residentado medico'],
            ['CondicionLaboralCodigo' => 'OTRO', 'CondicionLaboralNombre' => 'Otra condicion', 'CondicionLaboralEsPermanente' => 0, 'CondicionLaboralRequiereAirhsp' => 0, 'CondicionLaboralDescripcion' => null],
        ]);

        $this->sembrar('Personal.GrupoOcupacional', 'GrupoOcupacionalCodigo', [
            ['GrupoOcupacionalCodigo' => 'FUNCIONARIO', 'GrupoOcupacionalNombre' => 'Funcionario'],
            ['GrupoOcupacionalCodigo' => 'PROFESIONAL', 'GrupoOcupacionalNombre' => 'Profesional de la Salud'],
            ['GrupoOcupacionalCodigo' => 'PROF_ADM', 'GrupoOcupacionalNombre' => 'Profesional Administrativo'],
            ['GrupoOcupacionalCodigo' => 'TECNICO', 'GrupoOcupacionalNombre' => 'Tecnico'],
            ['GrupoOcupacionalCodigo' => 'AUXILIAR', 'GrupoOcupacionalNombre' => 'Auxiliar'],
            ['GrupoOcupacionalCodigo' => 'ASISTENCIAL', 'GrupoOcupacionalNombre' => 'Asistencial'],
        ]);

        $this->sembrar('Personal.Profesion', 'ProfesionCodigo', [
            ['ProfesionCodigo' => 'MEDICO', 'ProfesionNombre' => 'Medico Cirujano', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'ENFERMERIA', 'ProfesionNombre' => 'Enfermeria', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'OBSTETRICIA', 'ProfesionNombre' => 'Obstetricia', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'ODONTOLOGIA', 'ProfesionNombre' => 'Odontologia', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'PSICOLOGIA', 'ProfesionNombre' => 'Psicologia', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'NUTRICION', 'ProfesionNombre' => 'Nutricion', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'FARMACIA', 'ProfesionNombre' => 'Quimico Farmaceutico', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'BIOLOGIA', 'ProfesionNombre' => 'Biologia', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'TECMED', 'ProfesionNombre' => 'Tecnologia Medica', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'TRABSOCIAL', 'ProfesionNombre' => 'Trabajo Social', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'CONTABILIDAD', 'ProfesionNombre' => 'Contabilidad', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'ADMIN', 'ProfesionNombre' => 'Administracion', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'INGSISTEMAS', 'ProfesionNombre' => 'Ingenieria de Sistemas', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'DERECHO', 'ProfesionNombre' => 'Derecho', 'ProfesionRequiereColegiatura' => 1],
            ['ProfesionCodigo' => 'TEC_ENF', 'ProfesionNombre' => 'Tecnico en Enfermeria', 'ProfesionRequiereColegiatura' => 0],
            ['ProfesionCodigo' => 'TEC_ADM', 'ProfesionNombre' => 'Tecnico Administrativo', 'ProfesionRequiereColegiatura' => 0],
            ['ProfesionCodigo' => 'TEC_LAB', 'ProfesionNombre' => 'Tecnico de Laboratorio', 'ProfesionRequiereColegiatura' => 0],
            ['ProfesionCodigo' => 'SIN_PROF', 'ProfesionNombre' => 'Sin profesion registrada', 'ProfesionRequiereColegiatura' => 0],
        ]);

        $profesiones = $this->ids('Personal.Profesion', 'ProfesionCodigo', 'ProfesionId');
        $colegios = [
            ['CMP', 'Colegio Medico del Peru', 'MEDICO'],
            ['CEP', 'Colegio de Enfermeros del Peru', 'ENFERMERIA'],
            ['COP', 'Colegio de Obstetras del Peru', 'OBSTETRICIA'],
            ['COD', 'Colegio Odontologico del Peru', 'ODONTOLOGIA'],
            ['CPSP', 'Colegio de Psicologos del Peru', 'PSICOLOGIA'],
            ['CNP', 'Colegio de Nutricionistas del Peru', 'NUTRICION'],
            ['CQFP', 'Colegio Quimico Farmaceutico del Peru', 'FARMACIA'],
            ['CBP', 'Colegio de Biologos del Peru', 'BIOLOGIA'],
            ['CTMP', 'Colegio Tecnologo Medico del Peru', 'TECMED'],
            ['CTSP', 'Colegio de Trabajadores Sociales del Peru', 'TRABSOCIAL'],
            ['CCPP', 'Colegio de Contadores Publicos', 'CONTABILIDAD'],
            ['CLAD', 'Colegio de Licenciados en Administracion', 'ADMIN'],
            ['CIP', 'Colegio de Ingenieros del Peru', 'INGSISTEMAS'],
            ['CAL', 'Colegio de Abogados', 'DERECHO'],
        ];
        $this->sembrar('Personal.ColegiaturaTipo', 'ColegiaturaTipoCodigo', array_map(fn (array $c) => [
            'ColegiaturaTipoCodigo' => $c[0],
            'ColegiaturaTipoNombre' => $c[1],
            'ColegiaturaTipoEntidad' => $c[1],
            'ProfesionId' => $profesiones[$c[2]],
        ], $colegios));
    }

    private function configuracion(): void
    {
        $this->sembrar('Configuracion.TipoJornada', 'TipoJornadaCodigo', [
            ['TipoJornadaCodigo' => 'ADMIN', 'TipoJornadaNombre' => 'Jornada Administrativa', 'TipoJornadaDescripcion' => 'Jornada de lunes a viernes en horario de oficina'],
            ['TipoJornadaCodigo' => 'ASISTENC', 'TipoJornadaNombre' => 'Jornada Asistencial', 'TipoJornadaDescripcion' => 'Jornada del personal asistencial con rotacion'],
            ['TipoJornadaCodigo' => 'GUARDIA', 'TipoJornadaNombre' => 'Guardia', 'TipoJornadaDescripcion' => 'Jornada de guardia de 12 o 24 horas'],
            ['TipoJornadaCodigo' => 'PARCIAL', 'TipoJornadaNombre' => 'Jornada Parcial', 'TipoJornadaDescripcion' => 'Jornada de tiempo parcial'],
        ]);

        $jornadas = $this->ids('Configuracion.TipoJornada', 'TipoJornadaCodigo', 'TipoJornadaId');

        $horas = ['ADMIN' => [8, 48, 192], 'ASISTENC' => [6, 36, 150], 'GUARDIA' => [12, 36, 150], 'PARCIAL' => [4, 24, 96]];
        foreach ($horas as $codigo => [$diarias, $semanales, $mensuales]) {
            $existe = DB::table('Configuracion.ParametroJornada')
                ->where('TipoJornadaId', $jornadas[$codigo])
                ->where('ParametroJornadaVigenciaDesde', '2020-01-01')
                ->exists();
            if (! $existe) {
                DB::table('Configuracion.ParametroJornada')->insert([
                    'TipoJornadaId' => $jornadas[$codigo],
                    'ParametroJornadaVigenciaDesde' => '2020-01-01',
                    'ParametroJornadaHorasDiarias' => $diarias,
                    'ParametroJornadaHorasSemanales' => $semanales,
                    'ParametroJornadaHorasMensuales' => $mensuales,
                ]);
            }
        }

        $this->sembrar('Configuracion.TablaTolerancia', 'TablaToleranciaCodigo', [
            ['TablaToleranciaCodigo' => 'RIT_GENERAL', 'TablaToleranciaNombre' => 'Escala general RIT', 'TablaToleranciaDescripcion' => 'Escala de clasificacion y descuento por tardanza segun el Reglamento Interno de Trabajo'],
        ]);

        $tablaId = (int) DB::table('Configuracion.TablaTolerancia')->where('TablaToleranciaCodigo', 'RIT_GENERAL')->value('TablaToleranciaId');

        // [tipo, desde, hasta, minutos de descuento, es inasistencia, descripcion]. Minutos medidos desde la hora de ingreso del turno.
        $tramos = [
            ['TARDANZA', 1, 5, 0, 0, 'Tolerancia: sin descuento'],
            ['TARDANZA', 6, 10, 10, 0, 'Tardanza: descuento equivalente a 10 minutos'],
            ['TARDANZA', 11, 20, 20, 0, 'Tardanza: descuento equivalente a 20 minutos'],
            ['TARDANZA', 21, 30, 30, 0, 'Tardanza: descuento equivalente a 30 minutos'],
            ['TARDANZA', 31, null, null, 1, 'Inasistencia injustificada (a partir del minuto 31)'],
            ['SALIDA_ANTICIPADA', 1, null, null, 1, 'Salir antes de la hora sin autorizacion es inasistencia (Art. 23 b)'],
        ];
        foreach ($tramos as [$tipo, $desde, $hasta, $descuento, $esInasistencia, $descripcion]) {
            $existe = DB::table('Configuracion.TramoTolerancia')
                ->where('TablaToleranciaId', $tablaId)
                ->where('TramoToleranciaTipo', $tipo)
                ->where('TramoToleranciaMinutosDesde', $desde)
                ->exists();
            if (! $existe) {
                DB::table('Configuracion.TramoTolerancia')->insert([
                    'TablaToleranciaId' => $tablaId,
                    'TramoToleranciaTipo' => $tipo,
                    'TramoToleranciaMinutosDesde' => $desde,
                    'TramoToleranciaMinutosHasta' => $hasta,
                    'TramoToleranciaMinutosDescuento' => $descuento,
                    'TramoToleranciaEsInasistencia' => $esInasistencia,
                    'TramoToleranciaDescripcion' => $descripcion,
                ]);
            }
        }

        // [jornada, codigo, nombre, entrada, salida, refrigerio, es guardia]
        $turnos = [
            ['ADMIN', 'ADM-D', 'Administrativo sede 07:30-15:30', '07:30', '15:30', 45, 0],
            ['ASISTENC', 'M', 'Mañana 07:30-13:30', '07:30', '13:30', 0, 0],
            ['ASISTENC', 'T', 'Tarde 13:30-19:30', '13:30', '19:30', 0, 0],
            ['GUARDIA', 'N', 'Guardia nocturna 19:30-07:30', '19:30', '07:30', 0, 1],
            ['GUARDIA', 'G12-D', 'Guardia diurna 07:30-19:30', '07:30', '19:30', 0, 1],
        ];
        $this->sembrar('Configuracion.Turno', 'TurnoCodigo', array_map(fn (array $t) => [
            'TipoJornadaId' => $jornadas[$t[0]],
            'TablaToleranciaId' => $tablaId,
            'TurnoCodigo' => $t[1],
            'TurnoNombre' => $t[2],
            'TurnoHoraEntrada' => $t[3],
            'TurnoHoraSalida' => $t[4],
            'TurnoToleranciaEntradaMinutos' => 5,
            'TurnoRefrigerioMinutos' => $t[5],
            'TurnoEsGuardia' => $t[6],
        ], $turnos));

        // Horario administrativo de la sede (RIT Art. 16.1): 07:30-15:30 de lunes a viernes.
        $this->sembrar('Configuracion.Horario', 'HorarioCodigo', [
            ['TipoJornadaId' => $jornadas['ADMIN'], 'HorarioCodigo' => 'HOR-ADM-LV', 'HorarioNombre' => 'Administrativo Lunes a Viernes', 'HorarioDescripcion' => '07:30 a 15:30 de lunes a viernes'],
        ]);

        $horarioId = (int) DB::table('Configuracion.Horario')->where('HorarioCodigo', 'HOR-ADM-LV')->value('HorarioId');
        $turnoId = (int) DB::table('Configuracion.Turno')->where('TurnoCodigo', 'ADM-D')->value('TurnoId');
        foreach (range(1, 5) as $dia) {
            $existe = DB::table('Configuracion.HorarioDetalle')
                ->where(['HorarioId' => $horarioId, 'TurnoId' => $turnoId, 'HorarioDetalleDia' => $dia])
                ->exists();
            if (! $existe) {
                DB::table('Configuracion.HorarioDetalle')->insert([
                    'HorarioId' => $horarioId, 'TurnoId' => $turnoId, 'HorarioDetalleDia' => $dia,
                ]);
            }
        }
    }

    private function biometria(): void
    {
        $this->sembrar('Biometria.MetodoMarcacion', 'MetodoMarcacionCodigo', [
            ['MetodoMarcacionCodigo' => 'HUELLA', 'MetodoMarcacionNombre' => 'Huella dactilar'],
            ['MetodoMarcacionCodigo' => 'ROSTRO', 'MetodoMarcacionNombre' => 'Reconocimiento facial'],
            ['MetodoMarcacionCodigo' => 'TARJETA', 'MetodoMarcacionNombre' => 'Tarjeta de proximidad'],
            ['MetodoMarcacionCodigo' => 'CLAVE', 'MetodoMarcacionNombre' => 'Codigo o clave'],
            ['MetodoMarcacionCodigo' => 'MANUAL', 'MetodoMarcacionNombre' => 'Registro manual'],
            ['MetodoMarcacionCodigo' => 'APP', 'MetodoMarcacionNombre' => 'Aplicativo movil con geolocalizacion'],
        ]);
    }

    private function programacion(): void
    {
        // Mensual y quincenal SON EL MISMO CONCEPTO con distinto rango de fechas: filas de catalogo, no tablas.
        $this->sembrar('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionCodigo', [
            ['TipoPeriodoProgramacionCodigo' => 'MENSUAL', 'TipoPeriodoProgramacionNombre' => 'Programacion mensual', 'TipoPeriodoProgramacionDias' => 30],
            ['TipoPeriodoProgramacionCodigo' => 'QUINCENAL', 'TipoPeriodoProgramacionNombre' => 'Programacion quincenal', 'TipoPeriodoProgramacionDias' => 15],
            ['TipoPeriodoProgramacionCodigo' => 'SEMANAL', 'TipoPeriodoProgramacionNombre' => 'Programacion semanal', 'TipoPeriodoProgramacionDias' => 7],
            ['TipoPeriodoProgramacionCodigo' => 'EXTRAORD', 'TipoPeriodoProgramacionNombre' => 'Programacion extraordinaria', 'TipoPeriodoProgramacionDias' => null],
        ]);

        $this->sembrar('Programacion.TipoCambioTurno', 'TipoCambioTurnoCodigo', [
            ['TipoCambioTurnoCodigo' => 'REPROGRAMACION', 'TipoCambioTurnoNombre' => 'Reprogramacion', 'TipoCambioTurnoRequiereReemplazante' => 0, 'TipoCambioTurnoDescripcion' => 'La jefatura modifica el turno originalmente programado'],
            ['TipoCambioTurnoCodigo' => 'PERMUTA', 'TipoCambioTurnoNombre' => 'Permuta', 'TipoCambioTurnoRequiereReemplazante' => 1, 'TipoCambioTurnoDescripcion' => 'Dos trabajadores intercambian sus turnos'],
            ['TipoCambioTurnoCodigo' => 'REEMPLAZO', 'TipoCambioTurnoNombre' => 'Reemplazo', 'TipoCambioTurnoRequiereReemplazante' => 1, 'TipoCambioTurnoDescripcion' => 'Otro trabajador cubre el turno'],
            ['TipoCambioTurnoCodigo' => 'ANULACION', 'TipoCambioTurnoNombre' => 'Anulacion', 'TipoCambioTurnoRequiereReemplazante' => 0, 'TipoCambioTurnoDescripcion' => 'Se deja sin efecto el turno programado'],
        ]);
    }

    private function asistencia(): void
    {
        // EsFalta = 1 marca los estados JUSTIFICABLES por el modulo de justificacion.
        $estados = [
            ['ASISTIO', 'Asistio', 0, 0, 1],
            ['TARDANZA', 'Tardanza', 0, 1, 1],
            ['SALIDA_ANTIC', 'Salida anticipada', 0, 1, 1],
            ['FALTA', 'Falta injustificada', 1, 1, 1],
            ['FALTA_JUST', 'Falta justificada', 1, 0, 1],
            ['OMISION_MARCA', 'Omision de marcacion', 1, 0, 1],
            ['PAPELETA', 'Con papeleta autorizada', 0, 0, 1],
            ['COMISION', 'Comision de servicio', 0, 0, 1],
            ['LICENCIA', 'Con licencia', 0, 0, 1],
            ['DESCANSO_MED', 'Descanso medico', 0, 0, 1],
            ['VACACIONES', 'Vacaciones', 0, 0, 1],
            ['DESCANSO', 'Dia de descanso programado', 0, 0, 0],
            ['NO_LABORABLE', 'Dia no laborable / feriado', 0, 0, 0],
            ['CAPACITACION', 'Capacitacion autorizada', 0, 0, 1],
        ];
        $this->sembrar('Asistencia.EstadoAsistencia', 'EstadoAsistenciaCodigo', array_map(fn (array $e) => [
            'EstadoAsistenciaCodigo' => $e[0],
            'EstadoAsistenciaNombre' => $e[1],
            'EstadoAsistenciaEsFalta' => $e[2],
            'EstadoAsistenciaEsDescontable' => $e[3],
            'EstadoAsistenciaEsLaborable' => $e[4],
        ], $estados));

        $conceptos = [
            ['DESCANSO_MED', 'Descanso medico / CITT', 1, 1, 'Inasistencia por incapacidad temporal'],
            ['ENFERMEDAD_FAM', 'Enfermedad de familiar directo', 1, 1, 'Atencion de familiar directo acreditada'],
            ['DUELO', 'Fallecimiento de familiar', 1, 1, 'Duelo por familiar directo'],
            ['CITACION_JUD', 'Citacion judicial o policial', 1, 1, 'Comparecencia ante autoridad'],
            ['COMISION', 'Comision de servicio no registrada', 1, 1, 'Comision efectuada sin papeleta previa'],
            ['CAPACITACION', 'Capacitacion autorizada', 1, 1, 'Evento academico autorizado'],
            ['EMERGENCIA', 'Emergencia o caso fortuito', 0, 1, 'Situacion imprevista debidamente sustentada'],
            ['FALLA_EQUIPO', 'Falla del equipo de marcacion', 0, 1, 'La marcacion no se registro por causa tecnica'],
            ['OLVIDO_MARCA', 'Omision involuntaria de marcacion', 0, 1, 'El trabajador asistio pero no marco'],
            ['HUELGA', 'Paralizacion o huelga', 1, 0, 'Inasistencia por medida de fuerza'],
            ['OTRO', 'Otro motivo', 1, 0, 'Requiere evaluacion del responsable'],
        ];
        $this->sembrar('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionCodigo', array_map(fn (array $c) => [
            'ConceptoJustificacionCodigo' => $c[0],
            'ConceptoJustificacionNombre' => $c[1],
            'ConceptoJustificacionRequiereDocumento' => $c[2],
            'ConceptoJustificacionEsRemunerado' => $c[3],
            'ConceptoJustificacionDescripcion' => $c[4],
        ], $conceptos));
    }

    private function solicitudes(): void
    {
        $papeletas = [
            ['COMISION', 'Comision de servicio', 0, 1, 0, 'Salida por encargo institucional'],
            ['PERM_OFICIAL', 'Permiso oficial', 0, 1, 0, 'Permiso por razones de servicio'],
            ['PERM_PARTIC', 'Permiso particular', 1, 0, 1, 'Permiso por asunto personal, compensable'],
            ['PERM_SALUD', 'Permiso por salud', 0, 1, 0, 'Atencion medica del trabajador'],
            ['LACTANCIA', 'Permiso por lactancia', 0, 1, 0, 'Hora de lactancia materna'],
            ['ONOMASTICO', 'Dia de onomastico', 0, 0, 0, 'Descanso por cumpleanos'],
            ['CAPACITACION', 'Capacitacion', 0, 1, 0, 'Asistencia a evento academico'],
            ['SINDICAL', 'Licencia sindical', 0, 1, 0, 'Permiso por funcion sindical'],
            ['ESTUDIOS', 'Permiso por estudios', 1, 1, 1, 'Permiso por motivos academicos'],
        ];
        $this->sembrar('Solicitudes.TipoPapeleta', 'TipoPapeletaCodigo', array_map(fn (array $p) => [
            'TipoPapeletaCodigo' => $p[0],
            'TipoPapeletaNombre' => $p[1],
            'TipoPapeletaEsDescontable' => $p[2],
            'TipoPapeletaRequiereSustento' => $p[3],
            'TipoPapeletaEsCompensable' => $p[4],
            'TipoPapeletaDescripcion' => $p[5],
        ], $papeletas));

        $tipos = $this->ids('Solicitudes.TipoPapeleta', 'TipoPapeletaCodigo', 'TipoPapeletaId');
        $motivos = [
            ['COMISION', 'COM_REUNION', 'Reunion o coordinacion institucional'],
            ['COMISION', 'COM_TRAMITE', 'Tramite documentario en sede'],
            ['COMISION', 'COM_CAMPANA', 'Campana o actividad extramural'],
            ['PERM_PARTIC', 'PAR_PERSONAL', 'Asunto personal'],
            ['PERM_PARTIC', 'PAR_FAMILIAR', 'Asunto familiar'],
            ['PERM_SALUD', 'SAL_CONSULTA', 'Consulta medica'],
            ['PERM_SALUD', 'SAL_EXAMEN', 'Examen auxiliar o laboratorio'],
            ['LACTANCIA', 'LAC_HORA', 'Hora de lactancia'],
            ['CAPACITACION', 'CAP_CURSO', 'Curso o taller'],
            ['CAPACITACION', 'CAP_CONGRESO', 'Congreso o jornada cientifica'],
        ];
        $this->sembrar('Solicitudes.MotivoPapeleta', 'MotivoPapeletaCodigo', array_map(fn (array $m) => [
            'TipoPapeletaId' => $tipos[$m[0]],
            'MotivoPapeletaCodigo' => $m[1],
            'MotivoPapeletaNombre' => $m[2],
        ], $motivos));

        $licencias = [
            ['MATERNIDAD', 'Licencia por maternidad', 1, 98],
            ['PATERNIDAD', 'Licencia por paternidad', 1, 10],
            ['ENFERMEDAD', 'Licencia por enfermedad', 1, null],
            ['FAMILIAR', 'Licencia por familiar grave', 1, 7],
            ['FALLECIMIENTO', 'Licencia por fallecimiento', 1, 5],
            ['CAPACITACION', 'Licencia por capacitacion oficial', 1, null],
            ['SIN_GOCE', 'Licencia sin goce de haber', 0, null],
            ['ESTUDIOS', 'Licencia por estudios', 0, null],
            ['REPRESENT', 'Licencia por representacion', 1, null],
        ];
        $this->sembrar('Solicitudes.TipoLicencia', 'TipoLicenciaCodigo', array_map(fn (array $l) => [
            'TipoLicenciaCodigo' => $l[0],
            'TipoLicenciaNombre' => $l[1],
            'TipoLicenciaConGoce' => $l[2],
            'TipoLicenciaMaximoDias' => $l[3],
        ], $licencias));
    }

    private function compensaciones(): void
    {
        $this->sembrar('Compensaciones.TipoCompensacion', 'TipoCompensacionCodigo', [
            ['TipoCompensacionCodigo' => 'HORA_EXTRA', 'TipoCompensacionNombre' => 'Horas extras generadas'],
            ['TipoCompensacionCodigo' => 'GUARDIA', 'TipoCompensacionNombre' => 'Compensacion por guardia'],
            ['TipoCompensacionCodigo' => 'FERIADO', 'TipoCompensacionNombre' => 'Trabajo en dia no laborable'],
            ['TipoCompensacionCodigo' => 'PERMISO_COMP', 'TipoCompensacionNombre' => 'Devolucion de permiso particular'],
        ]);

        $this->sembrar('Compensaciones.ConceptoDescuento', 'ConceptoDescuentoCodigo', [
            ['ConceptoDescuentoCodigo' => 'DESC_FALTA', 'ConceptoDescuentoNombre' => 'Descuento por inasistencia injustificada'],
            ['ConceptoDescuentoCodigo' => 'DESC_TARDANZA', 'ConceptoDescuentoNombre' => 'Descuento por tardanza'],
            ['ConceptoDescuentoCodigo' => 'DESC_SALIDA', 'ConceptoDescuentoNombre' => 'Descuento por salida anticipada'],
            ['ConceptoDescuentoCodigo' => 'DESC_PERMISO', 'ConceptoDescuentoNombre' => 'Descuento por permiso particular no compensado'],
            ['ConceptoDescuentoCodigo' => 'DESC_LICENCIA', 'ConceptoDescuentoNombre' => 'Descuento por licencia sin goce'],
        ]);
    }

    private function disciplina(): void
    {
        $this->sembrar('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaCodigo', [
            ['TipoFaltaDisciplinariaCodigo' => 'INASIST_INJUST', 'TipoFaltaDisciplinariaNombre' => 'Inasistencia injustificada reiterada', 'TipoFaltaDisciplinariaGravedad' => 'GRAVE'],
            ['TipoFaltaDisciplinariaCodigo' => 'TARDANZA_REIT', 'TipoFaltaDisciplinariaNombre' => 'Tardanza reiterada', 'TipoFaltaDisciplinariaGravedad' => 'LEVE'],
            ['TipoFaltaDisciplinariaCodigo' => 'ABANDONO', 'TipoFaltaDisciplinariaNombre' => 'Abandono del puesto de trabajo', 'TipoFaltaDisciplinariaGravedad' => 'MUY_GRAVE'],
            ['TipoFaltaDisciplinariaCodigo' => 'MARCA_TERCERO', 'TipoFaltaDisciplinariaNombre' => 'Marcacion por tercero', 'TipoFaltaDisciplinariaGravedad' => 'MUY_GRAVE'],
            ['TipoFaltaDisciplinariaCodigo' => 'INCUMPL_HORARIO', 'TipoFaltaDisciplinariaNombre' => 'Incumplimiento de horario', 'TipoFaltaDisciplinariaGravedad' => 'LEVE'],
            ['TipoFaltaDisciplinariaCodigo' => 'NEGLIGENCIA', 'TipoFaltaDisciplinariaNombre' => 'Negligencia en el desempeno', 'TipoFaltaDisciplinariaGravedad' => 'GRAVE'],
        ]);
    }

    private function seguridad(): void
    {
        $this->sembrar('Seguridad.Rol', 'RolCodigo', [
            ['RolCodigo' => 'ADMIN', 'RolNombre' => 'Administrador del sistema', 'RolDescripcion' => 'Acceso total'],
            ['RolCodigo' => 'RRHH_RED', 'RolNombre' => 'Recursos Humanos - Red', 'RolDescripcion' => 'Gestion de personal de toda la Red'],
            ['RolCodigo' => 'JEFE_MICRORED', 'RolNombre' => 'Jefe de Microred', 'RolDescripcion' => 'Acceso a los EESS de su Microred'],
            ['RolCodigo' => 'RESP_EESS', 'RolNombre' => 'Responsable de EESS', 'RolDescripcion' => 'Gestion del personal de su establecimiento'],
            ['RolCodigo' => 'PROGRAMADOR', 'RolNombre' => 'Responsable de programacion', 'RolDescripcion' => 'Elabora y remite la programacion de turnos'],
            ['RolCodigo' => 'TRABAJADOR', 'RolNombre' => 'Trabajador', 'RolDescripcion' => 'Consulta de su propia asistencia y solicitudes'],
            ['RolCodigo' => 'PORTERIA', 'RolNombre' => 'Porteria', 'RolDescripcion' => 'Registro de ocurrencias'],
        ]);
    }

    private function parametrosDelSistema(): void
    {
        $this->sembrar('Configuracion.ParametroSistema', 'ParametroSistemaCodigo', [
            ['ParametroSistemaCodigo' => 'ENTIDAD_NOMBRE', 'ParametroSistemaValor' => 'Red de Salud Trujillo', 'ParametroSistemaDescripcion' => 'Nombre de la entidad'],
            ['ParametroSistemaCodigo' => 'DOC_STORAGE_BASE', 'ParametroSistemaValor' => '/storage/documentos', 'ParametroSistemaDescripcion' => 'Ruta base del almacenamiento externo de documentos'],
            ['ParametroSistemaCodigo' => 'DIAS_JUSTIFICAR_FALTA', 'ParametroSistemaValor' => '3', 'ParametroSistemaDescripcion' => 'Dias habiles para presentar la justificacion de una falta'],
            ['ParametroSistemaCodigo' => 'DIAS_ANTICIPO_PROG', 'ParametroSistemaValor' => '5', 'ParametroSistemaDescripcion' => 'Dias de anticipacion para remitir la programacion'],
            ['ParametroSistemaCodigo' => 'TOLERANCIA_GLOBAL_MIN', 'ParametroSistemaValor' => '5', 'ParametroSistemaDescripcion' => 'Minutos de tolerancia por defecto'],
        ]);
    }
}
