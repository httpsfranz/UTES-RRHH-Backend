<?php

namespace Tests\Support;

/**
 * Especificacion de los modulos de Nivel 0 (Hoja de Ruta de Dependencias) que usa
 * Nivel0CrudTest. Cada entrada espeja el DDL de database/sql/V001: que columnas
 * son obligatorias, cuales son UNIQUE y cual es el largo maximo de cada texto.
 *
 * Claves de cada modulo:
 *   endpoint  ruta base                     table/pk/estado  tabla, PK y columna BIT (null = DELETE fisico, false = sin DELETE)
 *   create    payload valido (PascalCase)    keys             claves que devuelve el Resource
 *   required  columnas NOT NULL             unique           columnas UNIQUE
 *   maxlen    largo maximo por columna       patch/patchKey   cambio parcial y clave del Resource que debe reflejarlo
 *   uniqueComposite  columna que reporta el error cuando el UNIQUE es compuesto (opcional)
 *   invalid   lista de [payload que pisa a create, columna que debe fallar]
 */
final class Nivel0Modulos
{
    /**
     * Catalogo estandar: Codigo + Nombre (+ Descripcion) + Estado.
     *
     * @param  array<string,mixed>  $extra
     * @param  list<string>  $extraKeys
     * @param  array<string,int>  $extraMax
     * @param  list<array{0: array<string,mixed>, 1: string}>  $invalid
     * @return array<string,mixed>
     */
    private static function catalogo(
        string $endpoint,
        string $table,
        string $prefix,
        int $codeMax,
        int $nameMax,
        ?int $descMax = 250,
        array $extra = [],
        array $extraKeys = [],
        array $extraMax = [],
        array $invalid = [],
    ): array {
        $create = [
            "{$prefix}Codigo" => 'ZZ_TEST',
            "{$prefix}Nombre" => 'Registro de prueba ZZ',
        ] + ($descMax ? ["{$prefix}Descripcion" => 'Descripcion de prueba'] : []) + $extra;

        return [
            'endpoint' => "/api/{$endpoint}",
            'table' => $table,
            'pk' => "{$prefix}Id",
            'estado' => "{$prefix}Estado",
            'create' => $create,
            'keys' => ['id', 'codigo', 'nombre', 'activo', ...($descMax ? ['descripcion'] : []), ...$extraKeys],
            'required' => ["{$prefix}Codigo", "{$prefix}Nombre"],
            'unique' => ["{$prefix}Codigo", "{$prefix}Nombre"],
            'maxlen' => ["{$prefix}Codigo" => $codeMax, "{$prefix}Nombre" => $nameMax]
                + ($descMax ? ["{$prefix}Descripcion" => $descMax] : []) + $extraMax,
            'patch' => ["{$prefix}Nombre" => 'Nombre actualizado ZZ'],
            'patchKey' => 'nombre',
            'invalid' => [
                [["{$prefix}Codigo" => 'con espacios'], "{$prefix}Codigo"],
                [["{$prefix}Estado" => 'quizas'], "{$prefix}Estado"],
                ...$invalid,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'microredes' => [
                'endpoint' => '/api/microredes', 'table' => 'Organizacion.Microred', 'pk' => 'MicroredId',
                'estado' => 'MicroredEstado',
                'create' => [
                    'MicroredCodigo' => 'ZZ-MR', 'MicroredNombre' => 'Microred ZZ', 'MicroredDistrito' => 'Laredo',
                    'MicroredUbigeo' => '130101', 'MicroredDireccion' => 'Av. Prueba 123',
                    'MicroredTelefono' => '987654321', 'MicroredDescripcion' => 'Prueba',
                ],
                'keys' => ['id', 'codigo', 'nombre', 'distrito', 'ubigeo', 'direccion', 'telefono', 'descripcion', 'activo'],
                'required' => ['MicroredCodigo', 'MicroredNombre'],
                'unique' => ['MicroredCodigo', 'MicroredNombre'],
                'maxlen' => ['MicroredCodigo' => 30, 'MicroredNombre' => 150, 'MicroredDistrito' => 100,
                    'MicroredDireccion' => 300, 'MicroredDescripcion' => 300],
                'patch' => ['MicroredDistrito' => 'Otro distrito'], 'patchKey' => 'distrito',
                'invalid' => [
                    [['MicroredTelefono' => 'abcdefghi'], 'MicroredTelefono'],
                    [['MicroredTelefono' => '12345'], 'MicroredTelefono'],
                    [['MicroredTelefono' => '9876543210'], 'MicroredTelefono'],
                    [['MicroredTelefono' => '98765432A'], 'MicroredTelefono'],
                    [['MicroredTelefono' => '+51 987 654 321'], 'MicroredTelefono'],
                    [['MicroredUbigeo' => '13A101'], 'MicroredUbigeo'],
                    [['MicroredUbigeo' => '1301'], 'MicroredUbigeo'],
                    [['MicroredCodigo' => 'con espacios'], 'MicroredCodigo'],
                    [['MicroredEstado' => 'quizas'], 'MicroredEstado'],
                ],
            ],
            'tipos-establecimiento' => self::catalogo('tipos-establecimiento', 'Organizacion.TipoEstablecimiento', 'TipoEstablecimiento', 30, 100),
            'tipos-responsabilidad' => self::catalogo('tipos-responsabilidad', 'Organizacion.TipoResponsabilidad', 'TipoResponsabilidad', 30, 150, 300),
            'grupos-ocupacionales' => self::catalogo('grupos-ocupacionales', 'Personal.GrupoOcupacional', 'GrupoOcupacional', 30, 100),
            'profesiones' => self::catalogo('profesiones', 'Personal.Profesion', 'Profesion', 30, 150, 300,
                ['ProfesionRequiereColegiatura' => true], ['requiere_colegiatura'], [],
                [[['ProfesionRequiereColegiatura' => 'tal vez'], 'ProfesionRequiereColegiatura']]),
            'tipos-colegiatura' => self::catalogo('tipos-colegiatura', 'Personal.ColegiaturaTipo', 'ColegiaturaTipo', 20, 150, 300,
                ['ColegiaturaTipoEntidad' => 'Colegio de Prueba'], ['profesion_id', 'entidad', 'profesion'], ['ColegiaturaTipoEntidad' => 200],
                [[['ProfesionId' => 999999], 'ProfesionId'], [['ProfesionId' => 'abc'], 'ProfesionId']]),
            'condiciones-laborales' => self::catalogo('condiciones-laborales', 'Personal.CondicionLaboral', 'CondicionLaboral', 30, 100, 250,
                ['CondicionLaboralEsPermanente' => false, 'CondicionLaboralRequiereAirhsp' => true], ['es_permanente', 'requiere_airhsp'], [],
                [[['CondicionLaboralEsPermanente' => 'x'], 'CondicionLaboralEsPermanente']]),
            'regimenes-laborales' => self::catalogo('regimenes-laborales', 'Personal.RegimenLaboral', 'RegimenLaboral', 30, 100, 250,
                ['RegimenLaboralBaseLegal' => 'Ley de prueba'], ['base_legal'], ['RegimenLaboralBaseLegal' => 150]),
            'tipos-documento-identidad' => self::catalogo('tipos-documento-identidad', 'Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidad', 20, 100, null,
                ['TipoDocumentoIdentidadAbreviatura' => 'ZZ', 'TipoDocumentoIdentidadLongitud' => 9], ['abreviatura', 'longitud'],
                ['TipoDocumentoIdentidadAbreviatura' => 20],
                [
                    [['TipoDocumentoIdentidadLongitud' => 'abc'], 'TipoDocumentoIdentidadLongitud'],
                    [['TipoDocumentoIdentidadLongitud' => 0], 'TipoDocumentoIdentidadLongitud'],
                    [['TipoDocumentoIdentidadLongitud' => 300], 'TipoDocumentoIdentidadLongitud'],
                    [['TipoDocumentoIdentidadLongitud' => 8.5], 'TipoDocumentoIdentidadLongitud'],
                ]),
            'tipos-cambio-turno' => self::catalogo('tipos-cambio-turno', 'Programacion.TipoCambioTurno', 'TipoCambioTurno', 30, 100, 250,
                ['TipoCambioTurnoRequiereReemplazante' => true], ['requiere_reemplazante']),
            'tipos-periodo-programacion' => self::catalogo('tipos-periodo-programacion', 'Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacion', 30, 100, 250,
                ['TipoPeriodoProgramacionDias' => 21], ['dias'], [],
                [
                    [['TipoPeriodoProgramacionDias' => 'muchos'], 'TipoPeriodoProgramacionDias'],
                    [['TipoPeriodoProgramacionDias' => 0], 'TipoPeriodoProgramacionDias'],
                    [['TipoPeriodoProgramacionDias' => 40000], 'TipoPeriodoProgramacionDias'],
                ]),
            'permisos' => self::catalogo('permisos', 'Seguridad.Permiso', 'Permiso', 100, 150, 300,
                ['PermisoModulo' => 'ZZ'], ['modulo'], ['PermisoModulo' => 60]),
            'roles' => self::catalogo('roles', 'Seguridad.Rol', 'Rol', 50, 100, 300),
            'tipos-licencia' => self::catalogo('tipos-licencia', 'Solicitudes.TipoLicencia', 'TipoLicencia', 30, 150, 300,
                ['TipoLicenciaConGoce' => true, 'TipoLicenciaMaximoDias' => 15, 'TipoLicenciaBaseLegal' => 'Norma'],
                ['con_goce', 'maximo_dias', 'base_legal'], ['TipoLicenciaBaseLegal' => 200],
                [
                    [['TipoLicenciaMaximoDias' => 'x'], 'TipoLicenciaMaximoDias'],
                    [['TipoLicenciaMaximoDias' => -1], 'TipoLicenciaMaximoDias'],
                    [['TipoLicenciaMaximoDias' => 40000], 'TipoLicenciaMaximoDias'],
                ]),
            'tipos-papeleta' => self::catalogo('tipos-papeleta', 'Solicitudes.TipoPapeleta', 'TipoPapeleta', 30, 150, 300,
                ['TipoPapeletaEsDescontable' => true, 'TipoPapeletaRequiereSustento' => false,
                    'TipoPapeletaAfectaJornada' => true, 'TipoPapeletaEsCompensable' => false],
                ['es_descontable', 'requiere_sustento', 'afecta_jornada', 'es_compensable']),
            'conceptos-justificacion' => self::catalogo('conceptos-justificacion', 'Asistencia.ConceptoJustificacion', 'ConceptoJustificacion', 30, 150, 300,
                ['ConceptoJustificacionRequiereDocumento' => true, 'ConceptoJustificacionEsRemunerado' => false],
                ['requiere_documento', 'es_remunerado']),
            'estados-asistencia' => self::catalogo('estados-asistencia', 'Asistencia.EstadoAsistencia', 'EstadoAsistencia', 30, 100, 250,
                ['EstadoAsistenciaEsFalta' => false, 'EstadoAsistenciaEsDescontable' => true, 'EstadoAsistenciaEsLaborable' => true],
                ['es_falta', 'es_descontable', 'es_laborable']),
            'metodos-marcacion' => self::catalogo('metodos-marcacion', 'Biometria.MetodoMarcacion', 'MetodoMarcacion', 50, 100, 250),
            'conceptos-descuento' => self::catalogo('conceptos-descuento', 'Compensaciones.ConceptoDescuento', 'ConceptoDescuento', 50, 150, 300),
            'tipos-compensacion' => self::catalogo('tipos-compensacion', 'Compensaciones.TipoCompensacion', 'TipoCompensacion', 30, 150, 300),
            'tablas-tolerancia' => self::catalogo('tablas-tolerancia', 'Configuracion.TablaTolerancia', 'TablaTolerancia', 30, 100, 250),
            'tipos-jornada' => self::catalogo('tipos-jornada', 'Configuracion.TipoJornada', 'TipoJornada', 30, 100, 250),
            'tipos-falta-disciplinaria' => self::catalogo('tipos-falta-disciplinaria', 'Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinaria', 50, 150, 300,
                ['TipoFaltaDisciplinariaGravedad' => 'LEVE', 'TipoFaltaDisciplinariaBaseLegal' => 'RIT'],
                ['gravedad', 'base_legal'], ['TipoFaltaDisciplinariaBaseLegal' => 200],
                [[['TipoFaltaDisciplinariaGravedad' => 'MEDIA'], 'TipoFaltaDisciplinariaGravedad']]),
            'parametros-sistema' => [
                'endpoint' => '/api/parametros-sistema', 'table' => 'Configuracion.ParametroSistema', 'pk' => 'ParametroSistemaId',
                'estado' => 'ParametroSistemaEstado',
                'create' => ['ParametroSistemaCodigo' => 'ZZ_PARAM', 'ParametroSistemaValor' => '10', 'ParametroSistemaDescripcion' => 'Prueba'],
                'keys' => ['id', 'codigo', 'valor', 'descripcion', 'activo'],
                'required' => ['ParametroSistemaCodigo'],
                'unique' => ['ParametroSistemaCodigo'],
                'maxlen' => ['ParametroSistemaCodigo' => 100, 'ParametroSistemaValor' => 500, 'ParametroSistemaDescripcion' => 300],
                'patch' => ['ParametroSistemaValor' => '20'], 'patchKey' => 'valor',
                'invalid' => [[['ParametroSistemaCodigo' => 'con espacios'], 'ParametroSistemaCodigo']],
            ],
            'dispositivos-marcacion' => [
                'endpoint' => '/api/dispositivos-marcacion', 'table' => 'Biometria.DispositivoMarcacion', 'pk' => 'DispositivoMarcacionId',
                'estado' => 'DispositivoMarcacionEstado',
                'create' => [
                    'DispositivoMarcacionCodigo' => 'ZZ-DISP', 'DispositivoMarcacionNombre' => 'Lector ZZ',
                    'DispositivoMarcacionTipo' => 'Facial', 'DispositivoMarcacionUbicacion' => 'Ingreso',
                    'DispositivoMarcacionIp' => '192.168.1.50',
                ],
                'keys' => ['id', 'eess_id', 'codigo', 'nombre', 'tipo', 'ubicacion', 'ip', 'activo', 'eess'],
                'required' => ['DispositivoMarcacionCodigo', 'DispositivoMarcacionNombre', 'DispositivoMarcacionTipo'],
                'unique' => ['DispositivoMarcacionCodigo'],
                'maxlen' => ['DispositivoMarcacionCodigo' => 50, 'DispositivoMarcacionNombre' => 100,
                    'DispositivoMarcacionTipo' => 50, 'DispositivoMarcacionUbicacion' => 200],
                'patch' => ['DispositivoMarcacionNombre' => 'Lector ZZ 2'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['EessId' => 999999], 'EessId'],
                    [['EessId' => 'abc'], 'EessId'],
                    [['DispositivoMarcacionIp' => '999.1.1.1'], 'DispositivoMarcacionIp'],
                    [['DispositivoMarcacionIp' => 'no-es-ip'], 'DispositivoMarcacionIp'],
                ],
            ],
            'calendario-no-laborable' => [
                'endpoint' => '/api/calendario-no-laborable', 'table' => 'Soporte.CalendarioNoLaborable', 'pk' => 'CalendarioNoLaborableId',
                'estado' => null,
                'create' => [
                    'CalendarioNoLaborableFecha' => '2031-07-28', 'CalendarioNoLaborableTipo' => 'FERIADO',
                    'CalendarioNoLaborableDescripcion' => 'Fiestas Patrias', 'CalendarioNoLaborableCompensable' => false,
                    'CalendarioNoLaborableNormaSustento' => 'Ley 29555',
                ],
                'keys' => ['id', 'microred_id', 'fecha', 'tipo', 'descripcion', 'compensable', 'norma_sustento', 'microred'],
                'required' => ['CalendarioNoLaborableFecha', 'CalendarioNoLaborableTipo'],
                'unique' => [], 'uniqueComposite' => 'CalendarioNoLaborableFecha',
                'maxlen' => ['CalendarioNoLaborableDescripcion' => 250, 'CalendarioNoLaborableNormaSustento' => 200],
                'patch' => ['CalendarioNoLaborableDescripcion' => 'Actualizado'], 'patchKey' => 'descripcion',
                'invalid' => [
                    [['CalendarioNoLaborableFecha' => '31/07/2031'], 'CalendarioNoLaborableFecha'],
                    [['CalendarioNoLaborableFecha' => 'manana'], 'CalendarioNoLaborableFecha'],
                    [['CalendarioNoLaborableTipo' => 'OTRO'], 'CalendarioNoLaborableTipo'],
                    [['MicroredId' => 999999], 'MicroredId'],
                    [['CalendarioNoLaborableCompensable' => 'quizas'], 'CalendarioNoLaborableCompensable'],
                ],
            ],
            'periodos-asistencia' => [
                'endpoint' => '/api/periodos-asistencia', 'table' => 'Consolidacion.PeriodoAsistencia', 'pk' => 'PeriodoAsistenciaId',
                'estado' => false,
                'create' => [
                    'PeriodoAsistenciaAnio' => 2031, 'PeriodoAsistenciaMes' => 7,
                    'PeriodoAsistenciaFechaInicio' => '2031-07-01', 'PeriodoAsistenciaFechaFin' => '2031-07-31',
                ],
                'keys' => ['id', 'anio', 'mes', 'fecha_inicio', 'fecha_fin', 'fecha_cierre', 'estado'],
                'required' => ['PeriodoAsistenciaAnio', 'PeriodoAsistenciaMes', 'PeriodoAsistenciaFechaInicio', 'PeriodoAsistenciaFechaFin'],
                'unique' => [], 'uniqueComposite' => 'PeriodoAsistenciaMes',
                'maxlen' => [],
                'patch' => ['PeriodoAsistenciaEstado' => 'EN_PROCESO'], 'patchKey' => 'estado',
                'invalid' => [
                    [['PeriodoAsistenciaMes' => 13], 'PeriodoAsistenciaMes'],
                    [['PeriodoAsistenciaMes' => 0], 'PeriodoAsistenciaMes'],
                    [['PeriodoAsistenciaAnio' => 99], 'PeriodoAsistenciaAnio'],
                    [['PeriodoAsistenciaAnio' => 'dos mil'], 'PeriodoAsistenciaAnio'],
                    [['PeriodoAsistenciaFechaFin' => '2031-06-30'], 'PeriodoAsistenciaFechaFin'],
                    [['PeriodoAsistenciaFechaInicio' => '01-07-2031'], 'PeriodoAsistenciaFechaInicio'],
                    [['PeriodoAsistenciaEstado' => 'ANULADO'], 'PeriodoAsistenciaEstado'],
                ],
            ],
            'documentos-sustento' => [
                'endpoint' => '/api/documentos-sustento', 'table' => 'Soporte.DocumentoSustento', 'pk' => 'DocumentoSustentoId',
                'estado' => null,
                'create' => [
                    'DocumentoSustentoNombre' => 'certificado-zz.pdf', 'DocumentoSustentoRuta' => '/storage/documentos/zz.pdf',
                    'DocumentoSustentoTipo' => 'application/pdf', 'DocumentoSustentoExtension' => 'pdf',
                    'DocumentoSustentoTamanoBytes' => 2048, 'DocumentoSustentoHash' => str_repeat('a', 64),
                ],
                'keys' => ['id', 'nombre', 'ruta', 'tipo', 'extension', 'tamano_bytes', 'hash', 'fecha_registro'],
                'required' => ['DocumentoSustentoNombre'],
                'unique' => ['DocumentoSustentoHash'],
                'maxlen' => ['DocumentoSustentoNombre' => 255, 'DocumentoSustentoRuta' => 500, 'DocumentoSustentoTipo' => 100,
                    'DocumentoSustentoExtension' => 10],
                'patch' => ['DocumentoSustentoNombre' => 'otro.pdf'], 'patchKey' => 'nombre',
                'invalid' => [
                    [['DocumentoSustentoTamanoBytes' => -5], 'DocumentoSustentoTamanoBytes'],
                    [['DocumentoSustentoTamanoBytes' => 'grande'], 'DocumentoSustentoTamanoBytes'],
                    [['DocumentoSustentoHash' => 'no-es-sha256'], 'DocumentoSustentoHash'],
                    [['DocumentoSustentoHash' => str_repeat('z', 64)], 'DocumentoSustentoHash'],
                    [['DocumentoSustentoHash' => str_repeat('a', 65)], 'DocumentoSustentoHash'],
                ],
            ],
        ];
    }
}
