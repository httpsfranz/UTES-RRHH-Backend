<?php

namespace Tests\Support;

/**
 * Especificacion de los modulos de Nivel 1 (Hoja de Ruta de Dependencias), con el formato de
 * Nivel0Modulos mas la clave `fk`: columna => [tabla, pk, filtro] con el id de la fila sembrada de la
 * que depende el modulo. RolPermiso se prueba aqui como CRUD de la tabla puente; la asignacion masiva
 * (PUT /roles/{rol}/permisos) tiene su propio test en Nivel1CrudTest.
 */
final class Nivel1Modulos
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'establecimientos' => [
                'endpoint' => '/api/establecimientos', 'table' => 'Organizacion.EstablecimientoSalud', 'pk' => 'EessId',
                'estado' => 'EessEstado',
                'fk' => [
                    'MicroredId' => ['Organizacion.Microred', 'MicroredId', ['MicroredCodigo' => 'MR-LE']],
                    'TipoEstablecimientoId' => ['Organizacion.TipoEstablecimiento', 'TipoEstablecimientoId', ['TipoEstablecimientoCodigo' => 'PS']],
                ],
                'create' => [
                    'EessCodigo' => 'ZZ-EESS', 'EessCodigoRenipres' => '99999901', 'EessNombre' => 'Establecimiento ZZ',
                    'EessCategoria' => 'I-2', 'EessUbigeo' => '130105', 'EessDireccion' => 'Av. Prueba 123',
                    'EessTelefono' => '987654321', 'EessDescripcion' => 'Prueba',
                ],
                'keys' => ['id', 'microred_id', 'tipo_establecimiento_id', 'codigo', 'renipres', 'nombre', 'categoria', 'ubigeo',
                    'direccion', 'telefono', 'descripcion', 'activo', 'microred', 'tipo'],
                'required' => ['MicroredId', 'TipoEstablecimientoId', 'EessCodigo', 'EessNombre'],
                'unique' => ['EessCodigo', 'EessCodigoRenipres'],
                'maxlen' => ['EessCodigo' => 30, 'EessNombre' => 150, 'EessDireccion' => 300, 'EessDescripcion' => 300],
                'patch' => ['EessNombre' => 'Establecimiento ZZ actualizado'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['EessCodigo' => 'con espacios'], 'EessCodigo'],
                    [['EessCodigoRenipres' => '1234'], 'EessCodigoRenipres'],
                    [['EessCodigoRenipres' => 'ABCDEFGH'], 'EessCodigoRenipres'],
                    [['EessCodigoRenipres' => '123456789'], 'EessCodigoRenipres'],
                    [['EessCategoria' => 'IV-1'], 'EessCategoria'],
                    [['EessUbigeo' => '12'], 'EessUbigeo'],
                    [['EessUbigeo' => '13A105'], 'EessUbigeo'],
                    [['EessTelefono' => '12345'], 'EessTelefono'],
                    [['EessTelefono' => 'abcdefghi'], 'EessTelefono'],
                    [['MicroredId' => 999999], 'MicroredId'],
                    [['MicroredId' => 'abc'], 'MicroredId'],
                    [['TipoEstablecimientoId' => 999999], 'TipoEstablecimientoId'],
                    [['EessEstado' => 'quizas'], 'EessEstado'],
                ],
            ],
            'trabajadores' => [
                'endpoint' => '/api/trabajadores', 'table' => 'Personal.Trabajador', 'pk' => 'TrabajadorId',
                'estado' => 'TrabajadorEstado',
                'fk' => [
                    'TipoDocumentoIdentidadId' => ['Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadId', ['TipoDocumentoIdentidadCodigo' => 'DNI']],
                ],
                'create' => [
                    'TrabajadorNumeroDocumento' => '79999901', 'TrabajadorNombres' => 'Ana María', 'TrabajadorApellidoPaterno' => 'Prueba',
                    'TrabajadorApellidoMaterno' => 'Zúñiga', 'TrabajadorSexo' => 'F', 'TrabajadorFechaNacimiento' => '1990-05-20',
                    'TrabajadorCorreo' => 'zz@ejemplo.pe', 'TrabajadorTelefono' => '987654321', 'TrabajadorDireccion' => 'Av. Prueba 123',
                    'TrabajadorFotoRuta' => '/storage/fotos/zz.jpg',
                ],
                'keys' => ['id', 'tipo_documento_id', 'profesion_id', 'numero_documento', 'nombres', 'apellido_paterno', 'apellido_materno',
                    'nombre_completo', 'sexo', 'fecha_nacimiento', 'correo', 'telefono', 'direccion', 'foto_ruta', 'fecha_registro',
                    'activo', 'tipo_documento', 'profesion'],
                'required' => ['TipoDocumentoIdentidadId', 'TrabajadorNumeroDocumento', 'TrabajadorNombres', 'TrabajadorApellidoPaterno'],
                'unique' => [], 'uniqueComposite' => 'TrabajadorNumeroDocumento',
                'maxlen' => ['TrabajadorNombres' => 100, 'TrabajadorApellidoPaterno' => 100, 'TrabajadorApellidoMaterno' => 100,
                    'TrabajadorCorreo' => 200, 'TrabajadorDireccion' => 300, 'TrabajadorFotoRuta' => 500],
                'patch' => ['TrabajadorNombres' => 'Ana Lucía'], 'patchKey' => 'nombres',
                'invalid' => [
                    [['TrabajadorNumeroDocumento' => 'ABCDEFGH'], 'TrabajadorNumeroDocumento'],
                    [['TrabajadorNumeroDocumento' => '1234567'], 'TrabajadorNumeroDocumento'],
                    [['TrabajadorNumeroDocumento' => '123456789'], 'TrabajadorNumeroDocumento'],
                    [['TrabajadorNumeroDocumento' => '7999 9901'], 'TrabajadorNumeroDocumento'],
                    [['TrabajadorNombres' => 'Juan2'], 'TrabajadorNombres'],
                    [['TrabajadorNombres' => '<script>'], 'TrabajadorNombres'],
                    [['TrabajadorApellidoPaterno' => '12345'], 'TrabajadorApellidoPaterno'],
                    [['TrabajadorSexo' => 'X'], 'TrabajadorSexo'],
                    [['TrabajadorFechaNacimiento' => '2020-01-01'], 'TrabajadorFechaNacimiento'],
                    [['TrabajadorFechaNacimiento' => '1850-01-01'], 'TrabajadorFechaNacimiento'],
                    [['TrabajadorFechaNacimiento' => '20/05/1990'], 'TrabajadorFechaNacimiento'],
                    [['TrabajadorCorreo' => 'no-es-correo'], 'TrabajadorCorreo'],
                    [['TrabajadorCorreo' => 'a@b'], 'TrabajadorCorreo'],
                    [['TrabajadorTelefono' => '12345'], 'TrabajadorTelefono'],
                    [['TrabajadorTelefono' => 'abcdefghi'], 'TrabajadorTelefono'],
                    [['TipoDocumentoIdentidadId' => 999999], 'TipoDocumentoIdentidadId'],
                    [['ProfesionId' => 999999], 'ProfesionId'],
                    [['TrabajadorEstado' => 'quizas'], 'TrabajadorEstado'],
                ],
            ],
            'cargos' => [
                'endpoint' => '/api/cargos', 'table' => 'Personal.Cargo', 'pk' => 'CargoId', 'estado' => 'CargoEstado',
                'fk' => ['GrupoOcupacionalId' => ['Personal.GrupoOcupacional', 'GrupoOcupacionalId', ['GrupoOcupacionalCodigo' => 'TECNICO']]],
                'create' => [
                    'CargoCodigo' => 'ZZ-CARGO', 'CargoNombre' => 'Cargo de prueba ZZ', 'CargoDescripcion' => 'Prueba', 'CargoEsJefatura' => true,
                ],
                'keys' => ['id', 'grupo_ocupacional_id', 'codigo', 'nombre', 'descripcion', 'es_jefatura', 'activo', 'grupo_ocupacional'],
                'required' => ['GrupoOcupacionalId', 'CargoNombre'],
                'unique' => ['CargoCodigo', 'CargoNombre'],
                'maxlen' => ['CargoCodigo' => 30, 'CargoNombre' => 150, 'CargoDescripcion' => 300],
                'patch' => ['CargoNombre' => 'Cargo ZZ actualizado'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['CargoCodigo' => 'con espacios'], 'CargoCodigo'],
                    [['GrupoOcupacionalId' => 999999], 'GrupoOcupacionalId'],
                    [['GrupoOcupacionalId' => 'abc'], 'GrupoOcupacionalId'],
                    [['CargoEsJefatura' => 'x'], 'CargoEsJefatura'],
                    [['CargoEstado' => 'quizas'], 'CargoEstado'],
                ],
            ],
            'turnos' => [
                'endpoint' => '/api/turnos', 'table' => 'Configuracion.Turno', 'pk' => 'TurnoId', 'estado' => 'TurnoEstado',
                'fk' => ['TipoJornadaId' => ['Configuracion.TipoJornada', 'TipoJornadaId', ['TipoJornadaCodigo' => 'ASISTENC']]],
                'create' => [
                    'TurnoCodigo' => 'ZZ-T', 'TurnoNombre' => 'Turno ZZ 08:00-14:00', 'TurnoHoraEntrada' => '08:00', 'TurnoHoraSalida' => '14:00',
                    'TurnoToleranciaEntradaMinutos' => 5, 'TurnoToleranciaSalidaMinutos' => 0, 'TurnoRefrigerioMinutos' => 0,
                    'TurnoPermiteHoraExtra' => false, 'TurnoEsGuardia' => false,
                ],
                'keys' => ['id', 'tipo_jornada_id', 'tabla_tolerancia_id', 'codigo', 'nombre', 'hora_entrada', 'hora_salida', 'cruza_medianoche',
                    'duracion_minutos', 'tolerancia_entrada_minutos', 'tolerancia_salida_minutos', 'refrigerio_minutos', 'permite_hora_extra',
                    'es_guardia', 'activo', 'tipo_jornada', 'tabla_tolerancia'],
                'required' => ['TipoJornadaId', 'TurnoCodigo', 'TurnoNombre', 'TurnoHoraEntrada', 'TurnoHoraSalida'],
                'unique' => ['TurnoCodigo', 'TurnoNombre'],
                'maxlen' => ['TurnoCodigo' => 30, 'TurnoNombre' => 100],
                'patch' => ['TurnoNombre' => 'Turno ZZ actualizado'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['TurnoCodigo' => 'con espacios'], 'TurnoCodigo'],
                    [['TurnoHoraEntrada' => '25:00'], 'TurnoHoraEntrada'],
                    [['TurnoHoraEntrada' => '8:00 am'], 'TurnoHoraEntrada'],
                    [['TurnoHoraSalida' => '14:61'], 'TurnoHoraSalida'],
                    [['TurnoHoraSalida' => 'abc'], 'TurnoHoraSalida'],
                    [['TurnoToleranciaEntradaMinutos' => -1], 'TurnoToleranciaEntradaMinutos'],
                    [['TurnoToleranciaEntradaMinutos' => 500], 'TurnoToleranciaEntradaMinutos'],
                    [['TurnoToleranciaEntradaMinutos' => 'x'], 'TurnoToleranciaEntradaMinutos'],
                    [['TurnoToleranciaSalidaMinutos' => 1.5], 'TurnoToleranciaSalidaMinutos'],
                    [['TurnoRefrigerioMinutos' => 500], 'TurnoRefrigerioMinutos'],
                    [['TurnoRefrigerioMinutos' => 360], 'TurnoRefrigerioMinutos'],
                    [['TurnoEsGuardia' => 'x'], 'TurnoEsGuardia'],
                    // RIT Art. 20: ni turnos ni guardias de mas de 12 horas, y una guardia dura exactamente 12.
                    [['TurnoHoraEntrada' => '06:00', 'TurnoHoraSalida' => '20:00'], 'TurnoHoraSalida'],
                    [['TurnoHoraEntrada' => '08:00', 'TurnoHoraSalida' => '08:00'], 'TurnoHoraSalida'],
                    [['TurnoEsGuardia' => true], 'TurnoHoraSalida'],
                    [['TipoJornadaId' => 999999], 'TipoJornadaId'],
                    [['TablaToleranciaId' => 999999], 'TablaToleranciaId'],
                    [['TurnoEstado' => 'quizas'], 'TurnoEstado'],
                ],
            ],
            'horarios' => [
                'endpoint' => '/api/horarios', 'table' => 'Configuracion.Horario', 'pk' => 'HorarioId', 'estado' => 'HorarioEstado',
                'fk' => ['TipoJornadaId' => ['Configuracion.TipoJornada', 'TipoJornadaId', ['TipoJornadaCodigo' => 'ASISTENC']]],
                'create' => [
                    'HorarioCodigo' => 'ZZ-HOR', 'HorarioNombre' => 'Horario ZZ', 'HorarioDescripcion' => 'Prueba', 'HorarioEsRotativo' => false,
                ],
                'keys' => ['id', 'tipo_jornada_id', 'eess_id', 'codigo', 'nombre', 'descripcion', 'es_rotativo', 'activo', 'tipo_jornada', 'eess'],
                'required' => ['TipoJornadaId', 'HorarioCodigo', 'HorarioNombre'],
                'unique' => ['HorarioCodigo', 'HorarioNombre'],
                'maxlen' => ['HorarioCodigo' => 30, 'HorarioNombre' => 150, 'HorarioDescripcion' => 300],
                'patch' => ['HorarioNombre' => 'Horario ZZ actualizado'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['HorarioCodigo' => 'con espacios'], 'HorarioCodigo'],
                    [['TipoJornadaId' => 999999], 'TipoJornadaId'],
                    [['EessId' => 999999], 'EessId'],
                    [['EessId' => 'abc'], 'EessId'],
                    [['HorarioEsRotativo' => 'x'], 'HorarioEsRotativo'],
                    [['HorarioEstado' => 'quizas'], 'HorarioEstado'],
                ],
            ],
            'parametros-jornada' => [
                'endpoint' => '/api/parametros-jornada', 'table' => 'Configuracion.ParametroJornada', 'pk' => 'ParametroJornadaId',
                'estado' => 'ParametroJornadaEstado',
                // PARCIAL ya tiene una vigencia abierta desde 2020: el payload usa un tramo historico que no la toca.
                'fk' => ['TipoJornadaId' => ['Configuracion.TipoJornada', 'TipoJornadaId', ['TipoJornadaCodigo' => 'PARCIAL']]],
                'create' => [
                    'ParametroJornadaVigenciaDesde' => '2005-01-01', 'ParametroJornadaVigenciaHasta' => '2009-12-31',
                    'ParametroJornadaHorasDiarias' => 7.5, 'ParametroJornadaHorasSemanales' => 37.5, 'ParametroJornadaHorasMensuales' => 160,
                ],
                'keys' => ['id', 'tipo_jornada_id', 'vigencia_desde', 'vigencia_hasta', 'horas_diarias', 'horas_semanales', 'horas_mensuales',
                    'activo', 'tipo_jornada'],
                'required' => ['TipoJornadaId', 'ParametroJornadaVigenciaDesde', 'ParametroJornadaHorasDiarias'],
                'unique' => [], 'uniqueComposite' => 'ParametroJornadaVigenciaDesde',
                'maxlen' => [],
                'patch' => ['ParametroJornadaHorasDiarias' => 6.5], 'patchKey' => 'horas_diarias',
                'invalid' => [
                    [['ParametroJornadaHorasDiarias' => 25], 'ParametroJornadaHorasDiarias'],
                    [['ParametroJornadaHorasDiarias' => -1], 'ParametroJornadaHorasDiarias'],
                    [['ParametroJornadaHorasDiarias' => 'x'], 'ParametroJornadaHorasDiarias'],
                    [['ParametroJornadaHorasDiarias' => 7.555], 'ParametroJornadaHorasDiarias'],
                    [['ParametroJornadaHorasSemanales' => 200], 'ParametroJornadaHorasSemanales'],
                    [['ParametroJornadaHorasSemanales' => 5], 'ParametroJornadaHorasSemanales'],
                    [['ParametroJornadaHorasMensuales' => 800], 'ParametroJornadaHorasMensuales'],
                    [['ParametroJornadaHorasMensuales' => 30], 'ParametroJornadaHorasMensuales'],
                    [['ParametroJornadaVigenciaHasta' => '2004-12-31'], 'ParametroJornadaVigenciaHasta'],
                    [['ParametroJornadaVigenciaDesde' => '01/01/2005'], 'ParametroJornadaVigenciaDesde'],
                    // Se superpone con la vigencia abierta de PARCIAL (desde 2020) o repite su fecha.
                    [['ParametroJornadaVigenciaDesde' => '2021-01-01', 'ParametroJornadaVigenciaHasta' => null], 'ParametroJornadaVigenciaDesde'],
                    [['ParametroJornadaVigenciaDesde' => '2020-01-01', 'ParametroJornadaVigenciaHasta' => null], 'ParametroJornadaVigenciaDesde'],
                    [['TipoJornadaId' => 999999], 'TipoJornadaId'],
                    [['ParametroJornadaEstado' => 'quizas'], 'ParametroJornadaEstado'],
                ],
            ],
            'tramos-tolerancia' => [
                'endpoint' => '/api/tramos-tolerancia', 'table' => 'Configuracion.TramoTolerancia', 'pk' => 'TramoToleranciaId',
                'estado' => null,
                // ESCALA_PRUEBA (DatosPruebaSeeder) nace sin tramos: no choca con la escala oficial RIT_GENERAL.
                'fk' => ['TablaToleranciaId' => ['Configuracion.TablaTolerancia', 'TablaToleranciaId', ['TablaToleranciaCodigo' => 'ESCALA_PRUEBA']]],
                'create' => [
                    'TramoToleranciaTipo' => 'TARDANZA', 'TramoToleranciaMinutosDesde' => 1, 'TramoToleranciaMinutosHasta' => 5,
                    'TramoToleranciaMinutosDescuento' => 0, 'TramoToleranciaEsInasistencia' => false, 'TramoToleranciaDescripcion' => 'Tolerancia',
                ],
                'keys' => ['id', 'tabla_tolerancia_id', 'tipo', 'minutos_desde', 'minutos_hasta', 'factor_descuento', 'minutos_descuento',
                    'es_inasistencia', 'descripcion', 'tabla_tolerancia'],
                'required' => ['TablaToleranciaId', 'TramoToleranciaTipo', 'TramoToleranciaMinutosDesde'],
                'unique' => [], 'uniqueComposite' => 'TramoToleranciaMinutosDesde',
                'maxlen' => ['TramoToleranciaDescripcion' => 250],
                'patch' => ['TramoToleranciaDescripcion' => 'Descripcion actualizada'], 'patchKey' => 'descripcion',
                'invalid' => [
                    [['TramoToleranciaTipo' => 'OTRO'], 'TramoToleranciaTipo'],
                    [['TramoToleranciaMinutosDesde' => -1], 'TramoToleranciaMinutosDesde'],
                    [['TramoToleranciaMinutosDesde' => 'x'], 'TramoToleranciaMinutosDesde'],
                    [['TramoToleranciaMinutosDesde' => 2000], 'TramoToleranciaMinutosDesde'],
                    [['TramoToleranciaMinutosHasta' => 0], 'TramoToleranciaMinutosHasta'],
                    [['TramoToleranciaMinutosHasta' => 'x'], 'TramoToleranciaMinutosHasta'],
                    [['TramoToleranciaFactorDescuento' => -1], 'TramoToleranciaFactorDescuento'],
                    [['TramoToleranciaFactorDescuento' => 'x'], 'TramoToleranciaFactorDescuento'],
                    [['TramoToleranciaFactorDescuento' => 1.234], 'TramoToleranciaFactorDescuento'],
                    [['TramoToleranciaMinutosDescuento' => -5], 'TramoToleranciaMinutosDescuento'],
                    [['TramoToleranciaEsInasistencia' => 'x'], 'TramoToleranciaEsInasistencia'],
                    [['TablaToleranciaId' => 999999], 'TablaToleranciaId'],
                ],
            ],
            'motivos-papeleta' => [
                'endpoint' => '/api/motivos-papeleta', 'table' => 'Solicitudes.MotivoPapeleta', 'pk' => 'MotivoPapeletaId',
                'estado' => 'MotivoPapeletaEstado',
                'fk' => ['TipoPapeletaId' => ['Solicitudes.TipoPapeleta', 'TipoPapeletaId', ['TipoPapeletaCodigo' => 'COMISION']]],
                'create' => ['MotivoPapeletaCodigo' => 'ZZ_MOT', 'MotivoPapeletaNombre' => 'Motivo ZZ', 'MotivoPapeletaDescripcion' => 'Prueba'],
                'keys' => ['id', 'tipo_papeleta_id', 'codigo', 'nombre', 'descripcion', 'activo', 'tipo_papeleta'],
                'required' => ['TipoPapeletaId', 'MotivoPapeletaCodigo', 'MotivoPapeletaNombre'],
                'unique' => ['MotivoPapeletaCodigo', 'MotivoPapeletaNombre'],
                'maxlen' => ['MotivoPapeletaCodigo' => 30, 'MotivoPapeletaNombre' => 150, 'MotivoPapeletaDescripcion' => 300],
                'patch' => ['MotivoPapeletaNombre' => 'Motivo ZZ actualizado'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['MotivoPapeletaCodigo' => 'con espacios'], 'MotivoPapeletaCodigo'],
                    [['TipoPapeletaId' => 999999], 'TipoPapeletaId'],
                    [['TipoPapeletaId' => 'abc'], 'TipoPapeletaId'],
                    [['MotivoPapeletaEstado' => 'quizas'], 'MotivoPapeletaEstado'],
                ],
            ],
            'roles-permisos' => [
                'endpoint' => '/api/roles-permisos', 'table' => 'Seguridad.RolPermiso', 'pk' => 'RolPermisoId', 'estado' => null,
                // PORTERIA no tiene REPORTES_EXPORTAR en los datos sembrados.
                'fk' => [
                    'RolId' => ['Seguridad.Rol', 'RolId', ['RolCodigo' => 'PORTERIA']],
                    'PermisoId' => ['Seguridad.Permiso', 'PermisoId', ['PermisoCodigo' => 'REPORTES_EXPORTAR']],
                ],
                'create' => [],
                'keys' => ['id', 'rol_id', 'permiso_id', 'activo', 'rol', 'permiso'],
                'required' => ['RolId', 'PermisoId'],
                'unique' => [], 'uniqueComposite' => 'PermisoId',
                'maxlen' => [],
                'patch' => ['RolPermisoEstado' => false], 'patchKey' => 'activo',
                'invalid' => [
                    [['RolId' => 999999], 'RolId'],
                    [['PermisoId' => 999999], 'PermisoId'],
                    [['PermisoId' => 'abc'], 'PermisoId'],
                    [['RolPermisoEstado' => 'quizas'], 'RolPermisoEstado'],
                ],
            ],
        ];
    }
}
