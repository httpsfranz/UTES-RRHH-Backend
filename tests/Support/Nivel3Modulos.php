<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\Feature\CrudModulosTestCase;

/**
 * Especificacion de los modulos de Nivel 3 con el formato de Nivel1Modulos y Nivel2Modulos, agrupados por lote:
 *   A asistencia y soporte, B solicitudes, C programacion/personal/seguridad, D consolidacion/compensaciones/vacaciones/disciplina.
 * En `fk`, una funcion crea al vuelo el vinculo (u otra fila) que el modulo necesita, para no chocar con los sembrados.
 */
final class Nivel3Modulos
{
    private static function sembrado(string $tabla, string $pk, array $filtro): array
    {
        return [$tabla, $pk, $filtro];
    }

    /** Vinculo laboral nuevo (trabajador nuevo, EESS-LE-01, desde 2026-01-01, sin fin). */
    public static function vinculo(): \Closure
    {
        return fn (CrudModulosTestCase $t) => $t->nuevoVinculo();
    }

    public static function usuario(string $nombre = 'rvargas'): array
    {
        return self::sembrado('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function loteA(): array
    {
        return [
            'marcaciones' => [
                'endpoint' => '/api/marcaciones', 'table' => 'Asistencia.Marcacion', 'pk' => 'MarcacionId', 'estado' => 'MarcacionEsValida',
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'MetodoMarcacionId' => self::sembrado('Biometria.MetodoMarcacion', 'MetodoMarcacionId', ['MetodoMarcacionCodigo' => 'ROSTRO']),
                ],
                'create' => ['MarcacionFechaHora' => '2026-09-21 07:30:00', 'MarcacionTipo' => 'ENTRADA'],
                'keys' => ['id', 'vinculo_laboral_id', 'metodo_marcacion_id', 'dispositivo_marcacion_id', 'plantilla_biometrica_id',
                    'carga_asistencia_manual_id', 'fecha_hora', 'tipo', 'geolocalizacion', 'observacion', 'origen', 'es_valida', 'activo',
                    'trabajador', 'vinculo', 'metodo', 'dispositivo'],
                'required' => ['VinculoLaboralId', 'MetodoMarcacionId', 'MarcacionFechaHora', 'MarcacionTipo'],
                'unique' => [], 'uniqueComposite' => 'MarcacionFechaHora',
                'maxlen' => ['MarcacionGeolocalizacion' => 100, 'MarcacionObservacion' => 500, 'MarcacionOrigen' => 50],
                'patch' => ['MarcacionObservacion' => 'Corregida por el responsable'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['MarcacionTipo' => 'ENTRADA_SALIDA'], 'MarcacionTipo'],
                    [['MarcacionFechaHora' => '28/09/2026 07:30'], 'MarcacionFechaHora'],
                    [['MarcacionFechaHora' => '2999-01-01 07:30:00'], 'MarcacionFechaHora'],
                    [['MarcacionFechaHora' => '2025-12-31 07:30:00'], 'MarcacionFechaHora'],   // antes de que el vinculo inicie
                    [['MarcacionFechaHora' => '2026-08-15 07:30:00'], 'MarcacionFechaHora'],   // periodo de asistencia cerrado
                    [['MarcacionGeolocalizacion' => 'no es una coordenada'], 'MarcacionGeolocalizacion'],
                    [['MarcacionOrigen' => 'minusculas'], 'MarcacionOrigen'],
                    [['MarcacionEsValida' => 'quizas'], 'MarcacionEsValida'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['MetodoMarcacionId' => 999999], 'MetodoMarcacionId'],
                    [['DispositivoMarcacionId' => 999999], 'DispositivoMarcacionId'],
                    [['PlantillaBiometricaId' => 999999], 'PlantillaBiometricaId'],
                    [['CargaAsistenciaManualId' => 999999], 'CargaAsistenciaManualId'],
                ],
            ],
            'asistencia-diaria' => [
                'endpoint' => '/api/asistencia-diaria', 'table' => 'Asistencia.AsistenciaDiaria', 'pk' => 'AsistenciaDiariaId', 'estado' => null,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'EstadoAsistenciaId' => self::sembrado('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', ['EstadoAsistenciaCodigo' => 'ASISTIO']),
                ],
                'create' => [
                    'AsistenciaDiariaFecha' => '2026-09-21', 'AsistenciaDiariaHoraEntrada' => '2026-09-21 07:30:00',
                    'AsistenciaDiariaHoraSalida' => '2026-09-21 13:30:00', 'AsistenciaDiariaObservacion' => 'Prueba',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'turno_programado_id', 'estado_asistencia_id', 'justificacion_falta_id', 'fecha',
                    'hora_entrada', 'hora_salida', 'minutos_tardanza', 'minutos_falta', 'minutos_extra', 'minutos_trabajados', 'observacion',
                    'fecha_proceso', 'trabajador', 'vinculo', 'estado'],
                'required' => ['VinculoLaboralId', 'EstadoAsistenciaId', 'AsistenciaDiariaFecha'],
                'unique' => [], 'uniqueComposite' => 'AsistenciaDiariaFecha',
                'maxlen' => ['AsistenciaDiariaObservacion' => 1000],
                'patch' => ['AsistenciaDiariaObservacion' => 'Corregida'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['AsistenciaDiariaFecha' => '21/09/2026'], 'AsistenciaDiariaFecha'],
                    [['AsistenciaDiariaFecha' => '2999-01-01'], 'AsistenciaDiariaFecha'],
                    [['AsistenciaDiariaFecha' => '2025-12-31'], 'AsistenciaDiariaFecha'],   // antes de que el vinculo inicie
                    [['AsistenciaDiariaFecha' => '2026-08-10'], 'AsistenciaDiariaFecha'],   // periodo de asistencia cerrado
                    [['AsistenciaDiariaHoraEntrada' => '2026-09-20 07:30:00'], 'AsistenciaDiariaHoraEntrada'],
                    [['AsistenciaDiariaHoraSalida' => '2026-09-21 06:00:00'], 'AsistenciaDiariaHoraSalida'],
                    [['AsistenciaDiariaHoraSalida' => '2026-09-23 07:30:00'], 'AsistenciaDiariaHoraSalida'],
                    [['AsistenciaDiariaMinutosTardanza' => 1441], 'AsistenciaDiariaMinutosTardanza'],
                    [['AsistenciaDiariaMinutosFalta' => -1], 'AsistenciaDiariaMinutosFalta'],
                    [['AsistenciaDiariaMinutosExtra' => 'mucho'], 'AsistenciaDiariaMinutosExtra'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['EstadoAsistenciaId' => 999999], 'EstadoAsistenciaId'],
                    [['TurnoProgramadoId' => 999999], 'TurnoProgramadoId'],
                    [['JustificacionFaltaId' => 999999], 'JustificacionFaltaId'],
                ],
            ],
            'justificaciones-falta' => [
                'endpoint' => '/api/justificaciones-falta', 'table' => 'Asistencia.JustificacionFalta', 'pk' => 'JustificacionFaltaId',
                'estado' => 'JustificacionFaltaEstado', 'anula' => true,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'ConceptoJustificacionId' => self::sembrado('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
                    'UsuarioRegistroId' => self::usuario(),
                ],
                'create' => [
                    'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-22',
                    'JustificacionFaltaObservacion' => 'Emergencia familiar',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'concepto_justificacion_id', 'documento_sustento_id', 'usuario_registro_id',
                    'usuario_resolucion_id', 'fecha_inicio', 'fecha_fin', 'documento_numero', 'observacion', 'motivo_rechazo', 'fecha_registro',
                    'fecha_resolucion', 'estado', 'activo', 'trabajador', 'vinculo', 'concepto', 'documento', 'usuario_registro', 'usuario_resolucion'],
                'required' => ['VinculoLaboralId', 'ConceptoJustificacionId', 'UsuarioRegistroId', 'JustificacionFaltaFechaInicio', 'JustificacionFaltaFechaFin'],
                'unique' => [], 'uniqueComposite' => 'JustificacionFaltaFechaInicio',
                'maxlen' => ['JustificacionFaltaDocumentoNumero' => 60, 'JustificacionFaltaObservacion' => 1000],
                'patch' => ['JustificacionFaltaObservacion' => 'Se adjunta constancia'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['JustificacionFaltaFechaInicio' => '21/09/2026'], 'JustificacionFaltaFechaInicio'],
                    [['JustificacionFaltaFechaFin' => '22/09/2026'], 'JustificacionFaltaFechaFin'],
                    [['JustificacionFaltaFechaFin' => '2026-09-20'], 'JustificacionFaltaFechaFin'],
                    [['JustificacionFaltaFechaInicio' => '2025-12-30', 'JustificacionFaltaFechaFin' => '2025-12-31'], 'VinculoLaboralId'],
                    [['JustificacionFaltaFechaInicio' => '2026-08-10', 'JustificacionFaltaFechaFin' => '2026-08-11'], 'JustificacionFaltaFechaInicio'],
                    [['JustificacionFaltaEstado' => 'APROBADO'], 'JustificacionFaltaEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['ConceptoJustificacionId' => 999999], 'ConceptoJustificacionId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'cargas-asistencia-manual' => [
                'endpoint' => '/api/cargas-asistencia-manual', 'table' => 'Asistencia.CargaAsistenciaManual', 'pk' => 'CargaAsistenciaManualId',
                'estado' => 'CargaAsistenciaManualEstado', 'anula' => true,
                'fk' => ['UsuarioId' => self::usuario()],
                'create' => [
                    'CargaAsistenciaManualNombreArchivo' => 'parte-zz.xlsx', 'CargaAsistenciaManualRegistros' => 10,
                    'CargaAsistenciaManualObservacion' => 'Prueba',
                ],
                'keys' => ['id', 'usuario_id', 'eess_id', 'documento_sustento_id', 'fecha', 'nombre_archivo', 'registros', 'observacion', 'estado',
                    'activo', 'usuario', 'eess', 'documento', 'marcaciones_registradas'],
                'required' => ['UsuarioId'],
                'unique' => [],
                'maxlen' => ['CargaAsistenciaManualNombreArchivo' => 255, 'CargaAsistenciaManualObservacion' => 1000],
                'patch' => ['CargaAsistenciaManualObservacion' => 'Revisada'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['CargaAsistenciaManualRegistros' => -1], 'CargaAsistenciaManualRegistros'],
                    [['CargaAsistenciaManualRegistros' => 'muchos'], 'CargaAsistenciaManualRegistros'],
                    [['CargaAsistenciaManualEstado' => 'ANULADO'], 'CargaAsistenciaManualEstado'],
                    [['CargaAsistenciaManualEstado' => 'CERRADO'], 'CargaAsistenciaManualEstado'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                    [['EessId' => 999999], 'EessId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'notificaciones' => [
                'endpoint' => '/api/notificaciones', 'table' => 'Soporte.Notificacion', 'pk' => 'NotificacionId', 'estado' => null,
                'fk' => ['UsuarioId' => self::usuario()],
                'create' => [
                    'NotificacionTipo' => 'GENERAL', 'NotificacionTitulo' => 'Aviso de prueba', 'NotificacionMensaje' => 'Mensaje de prueba',
                    'NotificacionEnlace' => '/solicitudes/papeletas',
                ],
                'keys' => ['id', 'usuario_id', 'tipo', 'titulo', 'mensaje', 'enlace', 'fecha', 'leida', 'usuario'],
                'required' => ['UsuarioId', 'NotificacionTipo', 'NotificacionTitulo', 'NotificacionMensaje'],
                'unique' => [],
                'maxlen' => ['NotificacionTipo' => 50, 'NotificacionTitulo' => 200, 'NotificacionMensaje' => 1000, 'NotificacionEnlace' => 300],
                'patch' => ['NotificacionLeida' => true], 'patchKey' => 'leida',
                'invalid' => [
                    [['NotificacionTipo' => 'minusculas'], 'NotificacionTipo'],
                    [['NotificacionEnlace' => 'sin-barra'], 'NotificacionEnlace'],
                    [['NotificacionLeida' => 'x'], 'NotificacionLeida'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function loteB(): array
    {
        return [
            'papeletas' => [
                'endpoint' => '/api/papeletas', 'table' => 'Solicitudes.Papeleta', 'pk' => 'PapeletaId', 'estado' => 'PapeletaEstado', 'anula' => true,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'TipoPapeletaId' => self::sembrado('Solicitudes.TipoPapeleta', 'TipoPapeletaId', ['TipoPapeletaCodigo' => 'PERM_OFICIAL']),
                ],
                'create' => [
                    'PapeletaNumero' => 'ZZ-PAP-1', 'PapeletaFecha' => '2026-09-21', 'PapeletaHoraSalida' => '10:00', 'PapeletaHoraRetorno' => '11:00',
                    'PapeletaMotivo' => 'Prueba',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'tipo_papeleta_id', 'motivo_papeleta_id', 'documento_sustento_id', 'usuario_registro_id',
                    'usuario_autorizacion_id', 'numero', 'fecha', 'hora_salida', 'hora_retorno', 'es_dia_completo', 'minutos_utilizados', 'motivo',
                    'observacion', 'fecha_registro', 'fecha_resolucion', 'estado', 'activo', 'trabajador', 'vinculo', 'tipo', 'motivo_papeleta',
                    'documento', 'usuario_registro', 'usuario_autorizacion'],
                'required' => ['VinculoLaboralId', 'TipoPapeletaId', 'PapeletaFecha'],
                'unique' => ['PapeletaNumero'],
                'maxlen' => ['PapeletaNumero' => 50, 'PapeletaMotivo' => 1000, 'PapeletaObservacion' => 1000],
                'patch' => ['PapeletaObservacion' => 'Visada por control de asistencia'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['PapeletaNumero' => '***'], 'PapeletaNumero'],
                    [['PapeletaFecha' => '21/09/2026'], 'PapeletaFecha'],
                    [['PapeletaFecha' => '2025-12-31'], 'PapeletaFecha'],   // antes de que el vinculo inicie
                    [['PapeletaFecha' => '2026-08-10'], 'PapeletaFecha'],   // periodo de asistencia cerrado
                    [['PapeletaHoraSalida' => '25:00'], 'PapeletaHoraSalida'],
                    [['PapeletaHoraRetorno' => '09:00'], 'PapeletaHoraRetorno'],
                    [['PapeletaEsDiaCompleto' => true], 'PapeletaEsDiaCompleto'],
                    [['PapeletaMinutosUtilizados' => 1441], 'PapeletaMinutosUtilizados'],
                    [['PapeletaEstado' => 'APROBADO'], 'PapeletaEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TipoPapeletaId' => 999999], 'TipoPapeletaId'],
                    [['MotivoPapeletaId' => 999999], 'MotivoPapeletaId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                ],
            ],
            'licencias' => [
                'endpoint' => '/api/licencias', 'table' => 'Solicitudes.Licencia', 'pk' => 'LicenciaId', 'estado' => 'LicenciaEstado', 'anula' => true,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'TipoLicenciaId' => self::sembrado('Solicitudes.TipoLicencia', 'TipoLicenciaId', ['TipoLicenciaCodigo' => 'CAPACITACION']),
                ],
                'create' => [
                    'LicenciaFechaInicio' => '2026-09-21', 'LicenciaFechaFin' => '2026-09-23', 'LicenciaNumeroResolucion' => 'RD-ZZ-1',
                    'LicenciaMotivo' => 'Prueba',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'tipo_licencia_id', 'documento_sustento_id', 'usuario_registro_id', 'numero_resolucion',
                    'fecha_inicio', 'fecha_fin', 'motivo', 'fecha_registro', 'estado', 'activo', 'trabajador', 'vinculo', 'tipo', 'documento', 'usuario_registro'],
                'required' => ['VinculoLaboralId', 'TipoLicenciaId', 'LicenciaFechaInicio', 'LicenciaFechaFin'],
                'unique' => [], 'uniqueComposite' => 'LicenciaFechaInicio',
                'maxlen' => ['LicenciaNumeroResolucion' => 60, 'LicenciaMotivo' => 1000],
                'patch' => ['LicenciaMotivo' => 'Diplomado en gestión pública'], 'patchKey' => 'motivo',
                'invalid' => [
                    [['LicenciaFechaInicio' => '21/09/2026'], 'LicenciaFechaInicio'],
                    [['LicenciaFechaFin' => '23/09/2026'], 'LicenciaFechaFin'],
                    [['LicenciaFechaFin' => '2026-09-20'], 'LicenciaFechaFin'],
                    [['LicenciaFechaInicio' => '2025-12-30', 'LicenciaFechaFin' => '2025-12-31'], 'VinculoLaboralId'],
                    [['LicenciaEstado' => 'APROBADO'], 'LicenciaEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TipoLicenciaId' => 999999], 'TipoLicenciaId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                ],
            ],
            'descansos-medicos' => [
                'endpoint' => '/api/descansos-medicos', 'table' => 'Solicitudes.DescansoMedico', 'pk' => 'DescansoMedicoId',
                'estado' => 'DescansoMedicoEstado', 'anula' => true,
                'fk' => ['VinculoLaboralId' => self::vinculo()],
                'create' => [
                    'DescansoMedicoNumeroCitt' => 'ZZ-CITT-1', 'DescansoMedicoDiagnostico' => 'Lumbalgia', 'DescansoMedicoFechaInicio' => '2026-09-21',
                    'DescansoMedicoFechaFin' => '2026-09-23',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'documento_sustento_id', 'numero_citt', 'diagnostico', 'fecha_inicio', 'fecha_fin', 'observacion',
                    'fecha_registro', 'estado', 'activo', 'trabajador', 'vinculo', 'documento'],
                'required' => ['VinculoLaboralId', 'DescansoMedicoFechaInicio', 'DescansoMedicoFechaFin'],
                'unique' => ['DescansoMedicoNumeroCitt'],
                'maxlen' => ['DescansoMedicoNumeroCitt' => 60, 'DescansoMedicoDiagnostico' => 300, 'DescansoMedicoObservacion' => 1000],
                'patch' => ['DescansoMedicoDiagnostico' => 'Lumbalgia aguda'], 'patchKey' => 'diagnostico',
                'invalid' => [
                    [['DescansoMedicoNumeroCitt' => '***'], 'DescansoMedicoNumeroCitt'],
                    [['DescansoMedicoFechaInicio' => '21/09/2026'], 'DescansoMedicoFechaInicio'],
                    [['DescansoMedicoFechaFin' => '2026-09-20'], 'DescansoMedicoFechaFin'],
                    [['DescansoMedicoFechaInicio' => '2025-12-30', 'DescansoMedicoFechaFin' => '2025-12-31'], 'VinculoLaboralId'],
                    [['DescansoMedicoEstado' => 'APROBADO'], 'DescansoMedicoEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'constataciones-domiciliarias' => [
                'endpoint' => '/api/constataciones-domiciliarias', 'table' => 'Solicitudes.ConstatacionDomiciliaria', 'pk' => 'ConstatacionDomiciliariaId',
                'estado' => 'ConstatacionDomiciliariaEstado', 'anula' => true,
                'fk' => ['VinculoLaboralId' => self::vinculo()],
                'create' => ['ConstatacionDomiciliariaFecha' => '2026-09-21', 'ConstatacionDomiciliariaDireccion' => 'Av. Prueba 123, Trujillo'],
                'keys' => ['id', 'vinculo_laboral_id', 'descanso_medico_id', 'documento_sustento_id', 'usuario_registro_id', 'fecha', 'direccion',
                    'resultado', 'estado', 'activo', 'trabajador', 'vinculo', 'descanso', 'documento', 'usuario_registro'],
                'required' => ['VinculoLaboralId', 'ConstatacionDomiciliariaFecha'],
                'unique' => [],
                'maxlen' => ['ConstatacionDomiciliariaDireccion' => 300, 'ConstatacionDomiciliariaResultado' => 500],
                'patch' => ['ConstatacionDomiciliariaDireccion' => 'Jr. Nueva 456, Trujillo'], 'patchKey' => 'direccion',
                'invalid' => [
                    [['ConstatacionDomiciliariaFecha' => '21/09/2026'], 'ConstatacionDomiciliariaFecha'],
                    [['ConstatacionDomiciliariaFecha' => '2025-12-31'], 'ConstatacionDomiciliariaFecha'],
                    [['ConstatacionDomiciliariaEstado' => 'ANULADO'], 'ConstatacionDomiciliariaEstado'],
                    [['ConstatacionDomiciliariaEstado' => 'CERRADA'], 'ConstatacionDomiciliariaEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['DescansoMedicoId' => 999999], 'DescansoMedicoId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                ],
            ],
        ];
    }

    /** Vinculo nuevo del regimen D.L. 276 (el que hace guardia comunitaria). */
    public static function vinculoDl276(): \Closure
    {
        return fn (CrudModulosTestCase $t) => $t->nuevoVinculo([
            'RegimenLaboralId' => DB::table('Personal.RegimenLaboral')->where('RegimenLaboralCodigo', 'DL276')->value('RegimenLaboralId'),
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function loteC(): array
    {
        $eessSinProgramacion = self::sembrado('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-02']);

        return [
            'programaciones-periodo' => [
                'endpoint' => '/api/programaciones-periodo', 'table' => 'Programacion.ProgramacionPeriodo', 'pk' => 'ProgramacionPeriodoId',
                'estado' => 'ProgramacionPeriodoEstado', 'anula' => 'ANULADA',
                'fk' => [
                    'EessId' => $eessSinProgramacion,
                    'TipoPeriodoProgramacionId' => self::sembrado('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionId', ['TipoPeriodoProgramacionCodigo' => 'MENSUAL']),
                    'UsuarioRegistroId' => self::usuario(),
                ],
                'create' => [
                    'ProgramacionPeriodoCodigo' => 'ZZ-PGM-1', 'ProgramacionPeriodoAnio' => 2026, 'ProgramacionPeriodoMes' => 11,
                    'ProgramacionPeriodoFechaInicio' => '2026-11-01', 'ProgramacionPeriodoFechaFin' => '2026-11-30', 'ProgramacionPeriodoObservacion' => 'Prueba',
                ],
                'keys' => ['id', 'eess_id', 'tipo_periodo_programacion_id', 'usuario_registro_id', 'codigo', 'anio', 'mes', 'numero', 'fecha_inicio',
                    'fecha_fin', 'observacion', 'fecha_registro', 'fecha_publicacion', 'estado', 'activo', 'eess', 'tipo_periodo', 'usuario_registro'],
                'required' => ['EessId', 'TipoPeriodoProgramacionId', 'UsuarioRegistroId', 'ProgramacionPeriodoAnio', 'ProgramacionPeriodoFechaInicio', 'ProgramacionPeriodoFechaFin'],
                'unique' => ['ProgramacionPeriodoCodigo'],
                'maxlen' => ['ProgramacionPeriodoCodigo' => 50, 'ProgramacionPeriodoObservacion' => 1000],
                'patch' => ['ProgramacionPeriodoObservacion' => 'Revisada por el jefe'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['ProgramacionPeriodoCodigo' => 'con espacios'], 'ProgramacionPeriodoCodigo'],
                    [['ProgramacionPeriodoAnio' => 1999], 'ProgramacionPeriodoAnio'],
                    [['ProgramacionPeriodoAnio' => 2025], 'ProgramacionPeriodoAnio'],   // no coincide con las fechas
                    [['ProgramacionPeriodoMes' => 13], 'ProgramacionPeriodoMes'],
                    [['ProgramacionPeriodoMes' => 10], 'ProgramacionPeriodoMes'],       // no coincide con las fechas
                    [['ProgramacionPeriodoNumero' => 6], 'ProgramacionPeriodoNumero'],
                    [['ProgramacionPeriodoFechaInicio' => '01/11/2026'], 'ProgramacionPeriodoFechaInicio'],
                    [['ProgramacionPeriodoFechaFin' => '30/11/2026'], 'ProgramacionPeriodoFechaFin'],
                    [['ProgramacionPeriodoFechaFin' => '2026-10-31'], 'ProgramacionPeriodoFechaFin'],
                    [['ProgramacionPeriodoFechaInicio' => '2026-11-02'], 'ProgramacionPeriodoFechaInicio'],   // el mes completo
                    [['ProgramacionPeriodoEstado' => 'PUBLICADA'], 'ProgramacionPeriodoEstado'],
                    [['EessId' => 999999], 'EessId'],
                    [['TipoPeriodoProgramacionId' => 999999], 'TipoPeriodoProgramacionId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                ],
            ],
            'cargas-programacion' => [
                'endpoint' => '/api/cargas-programacion', 'table' => 'Programacion.CargaProgramacion', 'pk' => 'CargaProgramacionId',
                'estado' => 'CargaProgramacionEstado', 'anula' => true,
                'fk' => [
                    'EessId' => $eessSinProgramacion,
                    'DocumentoSustentoId' => self::sembrado('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'citt-0002.pdf']),
                    'UsuarioRegistroId' => self::usuario(),
                ],
                'create' => [
                    'CargaProgramacionCodigo' => 'ZZ-PROG-1', 'CargaProgramacionAnio' => 2026, 'CargaProgramacionMes' => 11,
                    'CargaProgramacionFechaDocumento' => '2026-09-30', 'CargaProgramacionMotivo' => 'Prueba',
                ],
                'keys' => ['id', 'eess_id', 'documento_sustento_id', 'usuario_registro_id', 'tipo_periodo_programacion_id', 'programacion_periodo_id',
                    'codigo', 'anio', 'mes', 'numero', 'fecha_documento', 'documento_numero', 'motivo', 'observacion', 'fecha_registro', 'estado',
                    'activo', 'eess', 'documento', 'usuario_registro', 'tipo_periodo'],
                'required' => ['EessId', 'DocumentoSustentoId', 'UsuarioRegistroId', 'CargaProgramacionAnio', 'CargaProgramacionMes', 'CargaProgramacionFechaDocumento'],
                'unique' => ['CargaProgramacionCodigo'],
                'maxlen' => ['CargaProgramacionCodigo' => 50, 'CargaProgramacionDocumentoNumero' => 60, 'CargaProgramacionMotivo' => 500, 'CargaProgramacionObservacion' => 1000],
                'patch' => ['CargaProgramacionMotivo' => 'Programación de noviembre'], 'patchKey' => 'motivo',
                'invalid' => [
                    [['CargaProgramacionCodigo' => 'con espacios'], 'CargaProgramacionCodigo'],
                    [['CargaProgramacionAnio' => 1999], 'CargaProgramacionAnio'],
                    [['CargaProgramacionMes' => 13], 'CargaProgramacionMes'],
                    [['CargaProgramacionNumero' => 3], 'CargaProgramacionNumero'],
                    [['CargaProgramacionFechaDocumento' => '2999-01-01'], 'CargaProgramacionFechaDocumento'],
                    [['CargaProgramacionFechaDocumento' => '30/09/2026'], 'CargaProgramacionFechaDocumento'],
                    [['CargaProgramacionEstado' => 'ANULADO'], 'CargaProgramacionEstado'],
                    [['CargaProgramacionEstado' => 'CERRADA'], 'CargaProgramacionEstado'],
                    [['EessId' => 999999], 'EessId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                    [['TipoPeriodoProgramacionId' => 999999], 'TipoPeriodoProgramacionId'],
                    [['ProgramacionPeriodoId' => 999999], 'ProgramacionPeriodoId'],
                ],
            ],
            'informes-guardia-comunitaria' => [
                'endpoint' => '/api/informes-guardia-comunitaria', 'table' => 'Programacion.InformeGuardiaComunitaria', 'pk' => 'InformeGuardiaComunitariaId',
                'estado' => 'InformeGuardiaComunitariaEstado', 'anula' => true,
                'fk' => ['VinculoLaboralId' => self::vinculoDl276()],
                'create' => [
                    'InformeGuardiaComunitariaFecha' => '2026-09-20', 'InformeGuardiaComunitariaHoraInicio' => '08:00', 'InformeGuardiaComunitariaHoraFin' => '20:00',
                    'InformeGuardiaComunitariaDescripcion' => 'Visitas domiciliarias y educación sanitaria',
                ],
                'keys' => ['id', 'vinculo_laboral_id', 'turno_programado_id', 'documento_sustento_id', 'fecha', 'hora_inicio', 'hora_fin', 'descripcion',
                    'estado', 'activo', 'trabajador', 'vinculo', 'documento'],
                'required' => ['VinculoLaboralId', 'InformeGuardiaComunitariaFecha', 'InformeGuardiaComunitariaDescripcion'],
                'unique' => [], 'uniqueComposite' => 'InformeGuardiaComunitariaFecha',
                'maxlen' => ['InformeGuardiaComunitariaDescripcion' => 1000],
                'patch' => ['InformeGuardiaComunitariaDescripcion' => 'Campaña de vacunación'], 'patchKey' => 'descripcion',
                'invalid' => [
                    [['InformeGuardiaComunitariaFecha' => '20/09/2026'], 'InformeGuardiaComunitariaFecha'],
                    [['InformeGuardiaComunitariaFecha' => '2999-01-01'], 'InformeGuardiaComunitariaFecha'],
                    [['InformeGuardiaComunitariaFecha' => '2025-12-31'], 'InformeGuardiaComunitariaFecha'],   // antes de que el vinculo inicie
                    [['InformeGuardiaComunitariaHoraInicio' => '25:00'], 'InformeGuardiaComunitariaHoraInicio'],
                    [['InformeGuardiaComunitariaHoraFin' => '07:00'], 'InformeGuardiaComunitariaHoraFin'],
                    [['InformeGuardiaComunitariaHoraFin' => '21:00'], 'InformeGuardiaComunitariaHoraFin'],     // mas de 12 horas
                    [['InformeGuardiaComunitariaEstado' => 'APROBADO'], 'InformeGuardiaComunitariaEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TurnoProgramadoId' => 999999], 'TurnoProgramadoId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'asignaciones-horario' => [
                'endpoint' => '/api/asignaciones-horario', 'table' => 'Personal.AsignacionHorario', 'pk' => 'AsignacionHorarioId', 'estado' => 'AsignacionHorarioEstado',
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'HorarioId' => self::sembrado('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-ADM-LV']),
                ],
                'create' => ['AsignacionHorarioFechaInicio' => '2026-01-01', 'AsignacionHorarioObservacion' => 'Prueba'],
                'keys' => ['id', 'vinculo_laboral_id', 'horario_id', 'fecha_inicio', 'fecha_fin', 'observacion', 'fecha_registro', 'activo', 'trabajador', 'vinculo', 'horario'],
                'required' => ['VinculoLaboralId', 'HorarioId', 'AsignacionHorarioFechaInicio'],
                'unique' => [], 'uniqueComposite' => 'AsignacionHorarioFechaInicio',
                'maxlen' => ['AsignacionHorarioObservacion' => 500],
                'patch' => ['AsignacionHorarioObservacion' => 'Actualizada'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['AsignacionHorarioFechaInicio' => '01/01/2026'], 'AsignacionHorarioFechaInicio'],
                    [['AsignacionHorarioFechaFin' => '31/12/2026'], 'AsignacionHorarioFechaFin'],
                    [['AsignacionHorarioFechaFin' => '2025-12-31'], 'AsignacionHorarioFechaFin'],
                    [['AsignacionHorarioFechaInicio' => '2025-06-01'], 'VinculoLaboralId'],   // antes de que el vinculo inicie
                    [['AsignacionHorarioEstado' => 'quizas'], 'AsignacionHorarioEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['HorarioId' => 999999], 'HorarioId'],
                ],
            ],
            'responsables-eess' => [
                'endpoint' => '/api/responsables-eess', 'table' => 'Organizacion.ResponsableEess', 'pk' => 'ResponsableEessId', 'estado' => 'ResponsableEessEstado',
                'fk' => [
                    'EessId' => $eessSinProgramacion,
                    'VinculoLaboralId' => self::vinculo(),
                    'TipoResponsabilidadId' => self::sembrado('Organizacion.TipoResponsabilidad', 'TipoResponsabilidadId', ['TipoResponsabilidadCodigo' => 'COORDINADOR']),
                ],
                'create' => ['ResponsableEessFechaInicio' => '2026-01-01', 'ResponsableEessDocumentoNumero' => 'RD-ZZ-1', 'ResponsableEessObservacion' => 'Prueba'],
                'keys' => ['id', 'eess_id', 'vinculo_laboral_id', 'tipo_responsabilidad_id', 'documento_sustento_id', 'fecha_inicio', 'fecha_fin',
                    'documento_numero', 'observacion', 'fecha_registro', 'activo', 'eess', 'trabajador', 'vinculo', 'tipo_responsabilidad', 'documento'],
                'required' => ['EessId', 'VinculoLaboralId', 'TipoResponsabilidadId', 'ResponsableEessFechaInicio'],
                'unique' => [], 'uniqueComposite' => 'TipoResponsabilidadId',
                'maxlen' => ['ResponsableEessDocumentoNumero' => 60, 'ResponsableEessObservacion' => 500],
                'patch' => ['ResponsableEessObservacion' => 'Actualizada'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['ResponsableEessFechaInicio' => '01/01/2026'], 'ResponsableEessFechaInicio'],
                    [['ResponsableEessFechaFin' => '31/12/2026'], 'ResponsableEessFechaFin'],
                    [['ResponsableEessFechaFin' => '2025-12-31'], 'ResponsableEessFechaFin'],
                    [['ResponsableEessFechaInicio' => '2025-06-01'], 'VinculoLaboralId'],   // antes de que el vinculo inicie
                    [['ResponsableEessEstado' => 'quizas'], 'ResponsableEessEstado'],
                    [['EessId' => 999999], 'EessId'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TipoResponsabilidadId' => 999999], 'TipoResponsabilidadId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'usuarios-roles' => [
                'endpoint' => '/api/usuarios-roles', 'table' => 'Seguridad.UsuarioRol', 'pk' => 'UsuarioRolId', 'estado' => 'UsuarioRolEstado',
                'fk' => [
                    'UsuarioId' => self::usuario('vsanchez'),
                    'RolId' => self::sembrado('Seguridad.Rol', 'RolId', ['RolCodigo' => 'ADMIN']),
                ],
                'create' => ['UsuarioRolFechaInicio' => '2026-09-02'],
                'keys' => ['id', 'usuario_id', 'rol_id', 'fecha_inicio', 'fecha_fin', 'activo', 'usuario', 'rol'],
                'required' => ['UsuarioId', 'RolId'],
                'unique' => [], 'uniqueComposite' => 'RolId',
                'maxlen' => [],
                'patch' => ['UsuarioRolFechaFin' => '2026-12-31'], 'patchKey' => 'fecha_fin',
                'invalid' => [
                    [['UsuarioRolFechaInicio' => '02/09/2026'], 'UsuarioRolFechaInicio'],
                    [['UsuarioRolFechaFin' => '31/12/2026'], 'UsuarioRolFechaFin'],
                    [['UsuarioRolFechaFin' => '2026-09-01'], 'UsuarioRolFechaFin'],
                    [['UsuarioRolEstado' => 'quizas'], 'UsuarioRolEstado'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                    [['RolId' => 999999], 'RolId'],
                ],
            ],
            'usuarios-ambitos' => [
                'endpoint' => '/api/usuarios-ambitos', 'table' => 'Seguridad.UsuarioAmbito', 'pk' => 'UsuarioAmbitoId', 'estado' => 'UsuarioAmbitoEstado',
                'fk' => [
                    'UsuarioId' => self::usuario('vsanchez'),
                    'EessId' => $eessSinProgramacion,
                ],
                'create' => [],
                'keys' => ['id', 'usuario_id', 'microred_id', 'eess_id', 'activo', 'usuario', 'microred', 'eess', 'alcance'],
                'required' => ['UsuarioId'],
                'unique' => [], 'uniqueComposite' => 'UsuarioId',
                'maxlen' => [],
                'patch' => ['UsuarioAmbitoEstado' => false], 'patchKey' => 'activo',
                'invalid' => [
                    [['MicroredId' => 999999], 'MicroredId'],
                    [['EessId' => 999999], 'EessId'],
                    [['UsuarioAmbitoEstado' => 'quizas'], 'UsuarioAmbitoEstado'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function loteD(): array
    {
        return [
            'consolidados-asistencia' => [
                'endpoint' => '/api/consolidados-asistencia', 'table' => 'Consolidacion.ConsolidadoAsistencia', 'pk' => 'ConsolidadoAsistenciaId', 'estado' => null,
                'fk' => [
                    'PeriodoAsistenciaId' => self::sembrado('Consolidacion.PeriodoAsistencia', 'PeriodoAsistenciaId', ['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => 10]),
                    'VinculoLaboralId' => self::vinculo(),
                ],
                'create' => [
                    'ConsolidadoAsistenciaDiasTrabajados' => 20, 'ConsolidadoAsistenciaDiasFalta' => 1, 'ConsolidadoAsistenciaDiasFaltaJustificada' => 1,
                    'ConsolidadoAsistenciaMinutosTardanza' => 10, 'ConsolidadoAsistenciaMinutosExtra' => 60,
                ],
                'keys' => ['id', 'periodo_asistencia_id', 'vinculo_laboral_id', 'dias_trabajados', 'dias_falta', 'dias_falta_justificada', 'minutos_tardanza',
                    'minutos_extra', 'fecha_generacion', 'estado', 'activo', 'trabajador', 'vinculo', 'periodo'],
                'required' => ['PeriodoAsistenciaId', 'VinculoLaboralId'],
                'unique' => [], 'uniqueComposite' => 'VinculoLaboralId',
                'maxlen' => [],
                'patch' => ['ConsolidadoAsistenciaMinutosTardanza' => 15], 'patchKey' => 'minutos_tardanza',
                'invalid' => [
                    [['ConsolidadoAsistenciaDiasTrabajados' => 32], 'ConsolidadoAsistenciaDiasTrabajados'],
                    [['ConsolidadoAsistenciaDiasTrabajados' => -1], 'ConsolidadoAsistenciaDiasTrabajados'],
                    [['ConsolidadoAsistenciaDiasFalta' => 'muchos'], 'ConsolidadoAsistenciaDiasFalta'],
                    [['ConsolidadoAsistenciaDiasFaltaJustificada' => '1.234'], 'ConsolidadoAsistenciaDiasFaltaJustificada'],
                    [['ConsolidadoAsistenciaMinutosTardanza' => -5], 'ConsolidadoAsistenciaMinutosTardanza'],
                    [['ConsolidadoAsistenciaMinutosExtra' => 'x'], 'ConsolidadoAsistenciaMinutosExtra'],
                    [['ConsolidadoAsistenciaEstado' => 'CERRADO'], 'ConsolidadoAsistenciaEstado'],
                    [['ConsolidadoAsistenciaEstado' => 'XX'], 'ConsolidadoAsistenciaEstado'],
                    [['PeriodoAsistenciaId' => 999999], 'PeriodoAsistenciaId'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                ],
            ],
            'compensaciones-horarias' => [
                'endpoint' => '/api/compensaciones-horarias', 'table' => 'Compensaciones.CompensacionHoraria', 'pk' => 'CompensacionHorariaId',
                'estado' => 'CompensacionHorariaEstado', 'anula' => true,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'TipoCompensacionId' => self::sembrado('Compensaciones.TipoCompensacion', 'TipoCompensacionId', ['TipoCompensacionCodigo' => 'GUARDIA']),
                ],
                'create' => ['CompensacionHorariaHorasGeneradas' => 12, 'CompensacionHorariaAutorizadoPreviamente' => true, 'CompensacionHorariaObservacion' => 'Prueba'],
                'keys' => ['id', 'vinculo_laboral_id', 'tipo_compensacion_id', 'asistencia_diaria_id', 'autorizado_por', 'fecha_generacion', 'horas_generadas',
                    'horas_devueltas', 'fecha_limite', 'autorizado_previamente', 'observacion', 'estado', 'activo', 'horas_pendientes', 'vencida',
                    'trabajador', 'vinculo', 'tipo', 'usuario_autorizacion'],
                'required' => ['VinculoLaboralId', 'TipoCompensacionId', 'CompensacionHorariaHorasGeneradas'],
                'unique' => [],
                'maxlen' => ['CompensacionHorariaObservacion' => 500],
                'patch' => ['CompensacionHorariaObservacion' => 'Autorizada por el jefe inmediato'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['CompensacionHorariaHorasGeneradas' => 0], 'CompensacionHorariaHorasGeneradas'],
                    [['CompensacionHorariaHorasGeneradas' => 25], 'CompensacionHorariaHorasGeneradas'],
                    [['CompensacionHorariaHorasGeneradas' => 'muchas'], 'CompensacionHorariaHorasGeneradas'],
                    [['CompensacionHorariaHorasGeneradas' => '1.234'], 'CompensacionHorariaHorasGeneradas'],
                    [['CompensacionHorariaFechaLimite' => '30/10/2026'], 'CompensacionHorariaFechaLimite'],
                    [['CompensacionHorariaFechaLimite' => '2026-09-01'], 'CompensacionHorariaFechaLimite'],   // antes de generarse
                    [['CompensacionHorariaFechaLimite' => '2099-12-31'], 'CompensacionHorariaFechaLimite'],   // pasa del mes siguiente
                    [['CompensacionHorariaAutorizadoPreviamente' => 'x'], 'CompensacionHorariaAutorizadoPreviamente'],
                    [['CompensacionHorariaEstado' => 'APROBADO'], 'CompensacionHorariaEstado'],
                    [['CompensacionHorariaHorasDevueltas' => 1], 'CompensacionHorariaHorasDevueltas'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TipoCompensacionId' => 999999], 'TipoCompensacionId'],
                    [['AsistenciaDiariaId' => 999999], 'AsistenciaDiariaId'],
                    [['CompensacionHorariaAutorizadoPor' => 999999], 'CompensacionHorariaAutorizadoPor'],
                ],
            ],
            'periodos-vacacionales' => [
                'endpoint' => '/api/periodos-vacacionales', 'table' => 'Vacaciones.PeriodoVacacional', 'pk' => 'PeriodoVacacionalId',
                'estado' => 'PeriodoVacacionalEstado', 'anula' => true,
                'fk' => ['VinculoLaboralId' => self::vinculo()],
                'create' => ['PeriodoVacacionalAnio' => 2026, 'PeriodoVacacionalFechaInicio' => '2026-01-01', 'PeriodoVacacionalFechaFin' => '2026-12-31'],
                'keys' => ['id', 'vinculo_laboral_id', 'anio', 'fecha_inicio', 'fecha_fin', 'dias_ganados', 'dias_disponibles', 'estado', 'activo', 'trabajador', 'vinculo'],
                'required' => ['VinculoLaboralId', 'PeriodoVacacionalAnio', 'PeriodoVacacionalFechaInicio', 'PeriodoVacacionalFechaFin'],
                'unique' => [], 'uniqueComposite' => 'PeriodoVacacionalAnio',
                'maxlen' => [],
                'patch' => ['PeriodoVacacionalFechaFin' => '2027-01-31'], 'patchKey' => 'fecha_fin',
                'invalid' => [
                    [['PeriodoVacacionalAnio' => 1999], 'PeriodoVacacionalAnio'],
                    [['PeriodoVacacionalFechaInicio' => '01/01/2026'], 'PeriodoVacacionalFechaInicio'],
                    [['PeriodoVacacionalFechaFin' => '31/12/2026'], 'PeriodoVacacionalFechaFin'],
                    [['PeriodoVacacionalFechaFin' => '2025-12-31'], 'PeriodoVacacionalFechaFin'],
                    [['PeriodoVacacionalFechaInicio' => '2025-12-31', 'PeriodoVacacionalFechaFin' => '2026-12-30'], 'PeriodoVacacionalFechaInicio'],   // antes del vinculo
                    [['PeriodoVacacionalDiasGanados' => 31], 'PeriodoVacacionalDiasGanados'],
                    [['PeriodoVacacionalDiasDisponibles' => 31], 'PeriodoVacacionalDiasDisponibles'],
                    [['PeriodoVacacionalDiasDisponibles' => -1], 'PeriodoVacacionalDiasDisponibles'],
                    [['PeriodoVacacionalEstado' => 'ANULADO'], 'PeriodoVacacionalEstado'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                ],
            ],
            'expedientes-pad' => [
                'endpoint' => '/api/expedientes-pad', 'table' => 'Disciplina.ExpedientePad', 'pk' => 'ExpedientePadId', 'estado' => 'ExpedientePadEstado', 'anula' => true,
                'fk' => [
                    'VinculoLaboralId' => self::vinculo(),
                    'TipoFaltaDisciplinariaId' => self::sembrado('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaId', ['TipoFaltaDisciplinariaCodigo' => 'ABANDONO']),
                ],
                'create' => ['ExpedientePadNumero' => 'ZZ-PAD-1', 'ExpedientePadFechaInicio' => '2026-09-21', 'ExpedientePadDescripcion' => 'Prueba'],
                'keys' => ['id', 'vinculo_laboral_id', 'tipo_falta_disciplinaria_id', 'documento_sustento_id', 'numero', 'fecha_inicio', 'fecha_fin',
                    'descripcion', 'sancion', 'estado', 'activo', 'trabajador', 'vinculo', 'tipo_falta', 'documento'],
                'required' => ['VinculoLaboralId', 'TipoFaltaDisciplinariaId', 'ExpedientePadFechaInicio'],
                'unique' => ['ExpedientePadNumero'],
                'maxlen' => ['ExpedientePadNumero' => 50, 'ExpedientePadDescripcion' => 1500, 'ExpedientePadSancion' => 300],
                'patch' => ['ExpedientePadDescripcion' => 'Abandono del puesto en horas de labores'], 'patchKey' => 'descripcion',
                'invalid' => [
                    [['ExpedientePadNumero' => '***'], 'ExpedientePadNumero'],
                    [['ExpedientePadFechaInicio' => '21/09/2026'], 'ExpedientePadFechaInicio'],
                    [['ExpedientePadFechaFin' => '21/10/2026'], 'ExpedientePadFechaFin'],
                    [['ExpedientePadFechaFin' => '2026-09-01'], 'ExpedientePadFechaFin'],
                    [['ExpedientePadEstado' => 'ANULADO'], 'ExpedientePadEstado'],
                    [['ExpedientePadEstado' => 'XX'], 'ExpedientePadEstado'],
                    [['ExpedientePadEstado' => 'RESUELTO'], 'ExpedientePadEstado'],
                    [['ExpedientePadSancion' => 'Suspensión sin goce de remuneraciones'], 'ExpedientePadSancion'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['TipoFaltaDisciplinariaId' => 999999], 'TipoFaltaDisciplinariaId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'supervisiones-inopinadas' => [
                'endpoint' => '/api/supervisiones-inopinadas', 'table' => 'Disciplina.SupervisionInopinada', 'pk' => 'SupervisionInopinadaId',
                'estado' => 'SupervisionInopinadaEstado', 'anula' => true,
                'fk' => [
                    'EessId' => self::sembrado('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']),
                    'UsuarioId' => self::usuario('pgutierrez'),
                ],
                'create' => ['SupervisionInopinadaFechaHora' => '2026-09-21 10:00:00', 'SupervisionInopinadaResultado' => 'Personal presente'],
                'keys' => ['id', 'eess_id', 'vinculo_laboral_id', 'usuario_id', 'documento_sustento_id', 'fecha_hora', 'resultado', 'observacion', 'estado',
                    'activo', 'eess', 'trabajador', 'vinculo', 'usuario', 'documento'],
                'required' => ['EessId', 'UsuarioId'],
                'unique' => [],
                'maxlen' => ['SupervisionInopinadaResultado' => 500, 'SupervisionInopinadaObservacion' => 1000],
                'patch' => ['SupervisionInopinadaObservacion' => 'Se entregó copia del acta'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['SupervisionInopinadaFechaHora' => '21/09/2026 10:00'], 'SupervisionInopinadaFechaHora'],
                    [['SupervisionInopinadaFechaHora' => '2999-01-01 10:00:00'], 'SupervisionInopinadaFechaHora'],
                    [['SupervisionInopinadaEstado' => 'ANULADO'], 'SupervisionInopinadaEstado'],
                    [['SupervisionInopinadaEstado' => 'XX'], 'SupervisionInopinadaEstado'],
                    [['EessId' => 999999], 'EessId'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
        ];
    }
}
