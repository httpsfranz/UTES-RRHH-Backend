<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\Feature\CrudModulosTestCase;

/**
 * Especificacion de los modulos de los niveles 4, 5 y 6 con el formato de Nivel3Modulos. Las FK que dependen de un
 * escenario (una programacion en borrador, un consolidado, un periodo vacacional...) se crean al vuelo con ArmaEscenarios.
 * La liquidacion de descuentos no esta aqui: no se edita (solo se genera, aprueba, remite y anula) y tiene su propia prueba.
 */
final class Nivel4Modulos
{
    private static function sembrado(string $tabla, string $pk, array $filtro): array
    {
        return [$tabla, $pk, $filtro];
    }

    private static function usuario(string $nombre = 'rvargas'): array
    {
        return self::sembrado('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    /** Escenario de programacion en borrador (noviembre del C.S. La Esperanza) con un trabajador y su programacion. */
    private static function borrador(CrudModulosTestCase $t): array
    {
        return $t->recordar('borrador', function () use ($t) {
            $periodo = $t->periodoDeProgramacion('BORRADOR');
            $vinculo = $t->nuevoVinculo();

            return ['periodo' => $periodo, 'vinculo' => $vinculo, 'programacion' => $t->programacionDeTrabajador($periodo, $vinculo)];
        });
    }

    /** Un periodo vacacional abierto y su programacion del Rol de Vacaciones (diciembre). */
    private static function vacaciones(CrudModulosTestCase $t): array
    {
        return $t->recordar('vacaciones', function () use ($t) {
            $periodo = $t->periodoVacacional($t->nuevoVinculo());

            return ['periodo' => $periodo, 'rol' => $t->rolVacacional($periodo, '2026-12-07', 15)];
        });
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function nivel4(): array
    {
        return [
            'programaciones-trabajador' => [
                'endpoint' => '/api/programaciones-trabajador', 'table' => 'Programacion.ProgramacionTrabajador', 'pk' => 'ProgramacionTrabajadorId', 'estado' => null,
                'fk' => [
                    'ProgramacionPeriodoId' => fn (CrudModulosTestCase $t) => $t->periodoDeProgramacion('BORRADOR'),
                    'VinculoLaboralId' => fn (CrudModulosTestCase $t) => $t->nuevoVinculo(),
                ],
                'create' => ['ProgramacionTrabajadorObservacion' => 'Prueba'],
                'keys' => ['id', 'programacion_periodo_id', 'vinculo_laboral_id', 'horas_programadas', 'observacion', 'estado', 'activo', 'turnos_programados',
                    'trabajador', 'vinculo', 'periodo'],
                'required' => ['ProgramacionPeriodoId', 'VinculoLaboralId'],
                'unique' => [], 'uniqueComposite' => 'VinculoLaboralId',
                'maxlen' => ['ProgramacionTrabajadorObservacion' => 500],
                'patch' => ['ProgramacionTrabajadorObservacion' => 'Falta confirmar las guardias'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['ProgramacionPeriodoId' => 999999], 'ProgramacionPeriodoId'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['ProgramacionTrabajadorEstado' => 'PUBLICADA'], 'ProgramacionTrabajadorEstado'],
                    [['ProgramacionTrabajadorHorasProgramadas' => 150], 'ProgramacionTrabajadorHorasProgramadas'],
                    [['ProgramacionTrabajadorObservacion' => ['no es texto']], 'ProgramacionTrabajadorObservacion'],
                ],
            ],
            'ajustes-marcacion' => [
                'endpoint' => '/api/ajustes-marcacion', 'table' => 'Asistencia.AjusteMarcacion', 'pk' => 'AjusteMarcacionId',
                'estado' => 'AjusteMarcacionEstado', 'anula' => true,
                'fk' => [
                    'MarcacionId' => fn (CrudModulosTestCase $t) => $t->recordar('marcacion', fn () => DB::table('Asistencia.Marcacion')->insertGetId([
                        'VinculoLaboralId' => $t->nuevoVinculo(), 'MarcacionFechaHora' => '2026-09-22 07:30:00', 'MarcacionTipo' => 'ENTRADA',
                        'MetodoMarcacionId' => DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'ROSTRO')->value('MetodoMarcacionId'),
                    ], 'MarcacionId')),
                    'UsuarioId' => self::usuario(),
                ],
                'create' => ['AjusteMarcacionFechaHoraNueva' => '2026-09-22 07:20:00', 'AjusteMarcacionMotivo' => 'El reloj marcó con retraso'],
                'keys' => ['id', 'marcacion_id', 'usuario_id', 'documento_sustento_id', 'fecha_hora', 'fecha_hora_anterior', 'fecha_hora_nueva', 'motivo',
                    'estado', 'activo', 'trabajador', 'vinculo', 'marcacion', 'usuario', 'documento'],
                'required' => ['MarcacionId', 'UsuarioId', 'AjusteMarcacionMotivo'],
                'unique' => [], 'uniqueComposite' => 'MarcacionId',
                'maxlen' => ['AjusteMarcacionMotivo' => 1000],
                'patch' => ['AjusteMarcacionMotivo' => 'Ajuste confirmado con el jefe del establecimiento'], 'patchKey' => 'motivo',
                'invalid' => [
                    [['AjusteMarcacionFechaHoraNueva' => '22/09/2026 07:20'], 'AjusteMarcacionFechaHoraNueva'],
                    [['AjusteMarcacionFechaHoraNueva' => '2026-09-22 07:30:00'], 'AjusteMarcacionFechaHoraNueva'],   // igual a la actual
                    [['AjusteMarcacionFechaHoraNueva' => '2999-01-01 07:30:00'], 'AjusteMarcacionFechaHoraNueva'],
                    [['AjusteMarcacionFechaHoraNueva' => '2025-12-31 07:30:00'], 'AjusteMarcacionFechaHoraNueva'],   // antes de que el vinculo inicie
                    [['AjusteMarcacionFechaHoraNueva' => '2026-08-15 07:30:00'], 'AjusteMarcacionFechaHoraNueva'],   // periodo de asistencia cerrado
                    [['AjusteMarcacionEstado' => 'APROBADO'], 'AjusteMarcacionEstado'],
                    [['MarcacionId' => 999999], 'MarcacionId'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
            'detalles-consolidado' => [
                'endpoint' => '/api/detalles-consolidado', 'table' => 'Consolidacion.DetalleConsolidado', 'pk' => 'DetalleConsolidadoId', 'estado' => null,
                'fk' => [
                    'ConsolidadoAsistenciaId' => fn (CrudModulosTestCase $t) => $t->consolidado($t->nuevoVinculo(), 10),
                ],
                'create' => ['DetalleConsolidadoFecha' => '2026-10-05', 'DetalleConsolidadoEstado' => 'ASISTIO'],
                'keys' => ['id', 'consolidado_asistencia_id', 'asistencia_diaria_id', 'fecha', 'estado', 'minutos_tardanza', 'minutos_extra', 'es_justificada',
                    'trabajador', 'vinculo', 'consolidado'],
                'required' => ['ConsolidadoAsistenciaId', 'DetalleConsolidadoFecha', 'DetalleConsolidadoEstado'],
                'unique' => [], 'uniqueComposite' => 'DetalleConsolidadoFecha',
                'maxlen' => ['DetalleConsolidadoEstado' => 50],
                'patch' => ['DetalleConsolidadoMinutosExtra' => 45], 'patchKey' => 'minutos_extra',
                'invalid' => [
                    [['DetalleConsolidadoFecha' => '05/10/2026'], 'DetalleConsolidadoFecha'],
                    [['DetalleConsolidadoFecha' => '2026-11-02'], 'DetalleConsolidadoFecha'],   // fuera del periodo
                    [['DetalleConsolidadoEstado' => 'NO_EXISTE'], 'DetalleConsolidadoEstado'],
                    [['DetalleConsolidadoMinutosTardanza' => -1], 'DetalleConsolidadoMinutosTardanza'],
                    [['DetalleConsolidadoMinutosExtra' => 1500], 'DetalleConsolidadoMinutosExtra'],
                    [['DetalleConsolidadoMinutosExtra' => 'x'], 'DetalleConsolidadoMinutosExtra'],
                    [['DetalleConsolidadoEsJustificada' => 'quizas'], 'DetalleConsolidadoEsJustificada'],
                    [['ConsolidadoAsistenciaId' => 999999], 'ConsolidadoAsistenciaId'],
                    [['AsistenciaDiariaId' => 999999], 'AsistenciaDiariaId'],
                ],
            ],
            'roles-vacacionales' => [
                'endpoint' => '/api/roles-vacacionales', 'table' => 'Vacaciones.RolVacacional', 'pk' => 'RolVacacionalId',
                'estado' => 'RolVacacionalEstado', 'anula' => true,
                'fk' => [
                    'PeriodoVacacionalId' => fn (CrudModulosTestCase $t) => $t->periodoVacacional($t->nuevoVinculo()),
                ],
                'create' => ['RolVacacionalFechaProgramada' => '2026-12-07', 'RolVacacionalDias' => 15],
                'keys' => ['id', 'periodo_vacacional_id', 'fecha_programada', 'fecha_fin_programada', 'dias', 'estado', 'activo', 'goces_registrados',
                    'trabajador', 'vinculo', 'periodo_vacacional'],
                'required' => ['PeriodoVacacionalId', 'RolVacacionalFechaProgramada', 'RolVacacionalDias'],
                'unique' => [],
                'maxlen' => [],
                'patch' => ['RolVacacionalDias' => 10], 'patchKey' => 'dias',
                'invalid' => [
                    [['RolVacacionalDias' => 0], 'RolVacacionalDias'],
                    [['RolVacacionalDias' => 31], 'RolVacacionalDias'],
                    [['RolVacacionalDias' => 7.5], 'RolVacacionalDias'],
                    [['RolVacacionalDias' => 'muchos'], 'RolVacacionalDias'],
                    [['RolVacacionalFechaProgramada' => '07/12/2026'], 'RolVacacionalFechaProgramada'],
                    [['RolVacacionalFechaProgramada' => '2025-12-01'], 'RolVacacionalFechaProgramada'],   // antes de que el vinculo inicie
                    [['RolVacacionalFechaProgramada' => '2026-08-15'], 'RolVacacionalFechaProgramada'],   // periodo de asistencia cerrado
                    [['RolVacacionalFechaFinProgramada' => '2026-12-31'], 'RolVacacionalFechaFinProgramada'],   // no es la que resulta de los dias
                    [['RolVacacionalEstado' => 'GOZADO'], 'RolVacacionalEstado'],
                    [['PeriodoVacacionalId' => 999999], 'PeriodoVacacionalId'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function nivel5(): array
    {
        return [
            'turnos-programados' => [
                'endpoint' => '/api/turnos-programados', 'table' => 'Programacion.TurnoProgramado', 'pk' => 'TurnoProgramadoId', 'estado' => null,
                'fk' => [
                    'ProgramacionTrabajadorId' => fn (CrudModulosTestCase $t) => self::borrador($t)['programacion'],
                    'TurnoId' => self::sembrado('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'M']),
                ],
                'create' => ['TurnoProgramadoFecha' => '2026-11-03', 'TurnoProgramadoObservacion' => 'Prueba'],
                'keys' => ['id', 'programacion_trabajador_id', 'turno_id', 'fecha', 'hora_entrada', 'hora_salida', 'es_guardia', 'observacion', 'estado', 'activo',
                    'duracion_minutos', 'trabajador', 'vinculo', 'turno', 'programacion_trabajador'],
                'required' => ['ProgramacionTrabajadorId', 'TurnoId', 'TurnoProgramadoFecha'],
                'unique' => [], 'uniqueComposite' => 'TurnoId',
                'maxlen' => ['TurnoProgramadoObservacion' => 500],
                'patch' => ['TurnoProgramadoObservacion' => 'Cubre las vacaciones de la titular'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['TurnoProgramadoFecha' => '03/11/2026'], 'TurnoProgramadoFecha'],
                    [['TurnoProgramadoFecha' => '2026-12-05'], 'TurnoProgramadoFecha'],   // fuera del periodo
                    [['TurnoProgramadoFecha' => '2025-11-03'], 'TurnoProgramadoFecha'],
                    [['TurnoProgramadoHoraEntrada' => '10:00'], 'TurnoProgramadoHoraSalida'],   // una hora sin la otra
                    [['TurnoProgramadoHoraEntrada' => '25:00', 'TurnoProgramadoHoraSalida' => '13:00'], 'TurnoProgramadoHoraEntrada'],
                    [['TurnoProgramadoHoraEntrada' => '08:00', 'TurnoProgramadoHoraSalida' => '08:00'], 'TurnoProgramadoHoraSalida'],
                    [['TurnoProgramadoHoraEntrada' => '07:30', 'TurnoProgramadoHoraSalida' => '21:30'], 'TurnoProgramadoHoraSalida'],   // mas de 12 horas
                    [['TurnoProgramadoEsGuardia' => 'quizas'], 'TurnoProgramadoEsGuardia'],
                    [['TurnoProgramadoEstado' => 'CUMPLIDO'], 'TurnoProgramadoEstado'],
                    [['TurnoId' => 999999], 'TurnoId'],
                    [['ProgramacionTrabajadorId' => 999999], 'ProgramacionTrabajadorId'],
                ],
            ],
            'goces-vacacionales' => [
                'endpoint' => '/api/goces-vacacionales', 'table' => 'Vacaciones.GoceVacacional', 'pk' => 'GoceVacacionalId',
                'estado' => 'GoceVacacionalEstado', 'anula' => true,
                'fk' => [
                    'RolVacacionalId' => fn (CrudModulosTestCase $t) => self::vacaciones($t)['rol'],
                ],
                'create' => ['GoceVacacionalFechaInicio' => '2026-12-07', 'GoceVacacionalFechaFin' => '2026-12-13'],
                'keys' => ['id', 'rol_vacacional_id', 'documento_sustento_id', 'fecha_inicio', 'fecha_fin', 'dias', 'estado', 'activo',
                    'trabajador', 'vinculo', 'rol_vacacional', 'documento'],
                'required' => ['RolVacacionalId', 'GoceVacacionalFechaInicio', 'GoceVacacionalFechaFin'],
                'unique' => [],
                'maxlen' => [],
                'patch' => ['GoceVacacionalFechaFin' => '2026-12-14'], 'patchKey' => 'fecha_fin',
                'invalid' => [
                    [['GoceVacacionalFechaInicio' => '07/12/2026'], 'GoceVacacionalFechaInicio'],
                    [['GoceVacacionalFechaFin' => '2026-12-06'], 'GoceVacacionalFechaFin'],   // antes del inicio
                    [['GoceVacacionalFechaFin' => '2026-12-30'], 'GoceVacacionalFechaInicio'],   // fuera de lo programado
                    [['GoceVacacionalFechaFin' => '2026-12-09'], 'DocumentoSustentoId'],   // 3 dias: fraccionamiento sin documento
                    [['GoceVacacionalDias' => 3], 'GoceVacacionalDias'],
                    [['GoceVacacionalDias' => 'x'], 'GoceVacacionalDias'],
                    [['GoceVacacionalEstado' => 'APROBADO'], 'GoceVacacionalEstado'],
                    [['RolVacacionalId' => 999999], 'RolVacacionalId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                ],
            ],
        ];
    }

    /**
     * Linea de la liquidacion de descuentos (Nivel 5). Su cabecera, de Nivel 4, no es un CRUD y se prueba aparte.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function liquidacion(): array
    {
        return [
            'detalles-liquidacion' => [
                'endpoint' => '/api/detalles-liquidacion', 'table' => 'Compensaciones.DetalleLiquidacion', 'pk' => 'DetalleLiquidacionId', 'estado' => null,
                'fk' => [
                    'LiquidacionDescuentoId' => fn (CrudModulosTestCase $t) => $t->liquidacionDeDescuentos(),
                    'ConceptoDescuentoId' => self::sembrado('Compensaciones.ConceptoDescuento', 'ConceptoDescuentoId', ['ConceptoDescuentoCodigo' => 'DESC_PERMISO']),
                ],
                'create' => ['DetalleLiquidacionCantidad' => 2, 'DetalleLiquidacionImporte' => 200, 'DetalleLiquidacionObservacion' => 'Prueba'],
                'keys' => ['id', 'liquidacion_descuento_id', 'concepto_descuento_id', 'cantidad', 'importe', 'observacion', 'liquidacion', 'concepto'],
                'required' => ['LiquidacionDescuentoId', 'ConceptoDescuentoId'],
                'unique' => [], 'uniqueComposite' => 'ConceptoDescuentoId',
                'maxlen' => ['DetalleLiquidacionObservacion' => 500],
                'patch' => ['DetalleLiquidacionImporte' => 250], 'patchKey' => 'importe',
                'invalid' => [
                    [['DetalleLiquidacionCantidad' => -1], 'DetalleLiquidacionCantidad'],
                    [['DetalleLiquidacionCantidad' => 'x'], 'DetalleLiquidacionCantidad'],
                    [['DetalleLiquidacionImporte' => -5], 'DetalleLiquidacionImporte'],
                    [['DetalleLiquidacionImporte' => 'x'], 'DetalleLiquidacionImporte'],
                    [['DetalleLiquidacionImporte' => '1.234'], 'DetalleLiquidacionImporte'],
                    [['ConceptoDescuentoId' => 999999], 'ConceptoDescuentoId'],
                    [['LiquidacionDescuentoId' => 999999], 'LiquidacionDescuentoId'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function nivel6(): array
    {
        return [
            'cambios-turno' => [
                'endpoint' => '/api/cambios-turno', 'table' => 'Programacion.CambioTurno', 'pk' => 'CambioTurnoId',
                'estado' => 'CambioTurnoEstado', 'anula' => true,
                'fk' => [
                    'TipoCambioTurnoId' => self::sembrado('Programacion.TipoCambioTurno', 'TipoCambioTurnoId', ['TipoCambioTurnoCodigo' => 'REEMPLAZO']),
                    'TurnoProgramadoId' => fn (CrudModulosTestCase $t) => $t->escenarioDeCambioDeTurno()['turno'],
                    'VinculoLaboralSolicitanteId' => fn (CrudModulosTestCase $t) => $t->escenarioDeCambioDeTurno()['solicitante'],
                    'VinculoLaboralReemplazanteId' => fn (CrudModulosTestCase $t) => $t->escenarioDeCambioDeTurno()['reemplazante'],
                    'UsuarioRegistroId' => self::usuario(),
                ],
                'create' => ['CambioTurnoMotivo' => 'Cita médica programada con anticipación'],
                'keys' => ['id', 'tipo_cambio_turno_id', 'turno_programado_id', 'turno_programado_contraparte_id', 'turno_id_nuevo', 'vinculo_laboral_solicitante_id',
                    'vinculo_laboral_reemplazante_id', 'documento_sustento_id', 'usuario_registro_id', 'usuario_aprobacion_id', 'fecha_solicitud',
                    'fecha_resolucion', 'motivo', 'observacion', 'estado', 'activo', 'tipo', 'turno_programado', 'contraparte', 'turno_nuevo', 'solicitante',
                    'reemplazante', 'documento', 'usuario_registro', 'usuario_aprobacion'],
                'required' => ['TipoCambioTurnoId', 'TurnoProgramadoId', 'VinculoLaboralSolicitanteId', 'UsuarioRegistroId'],
                'unique' => [], 'uniqueComposite' => 'TurnoProgramadoId',
                'maxlen' => ['CambioTurnoMotivo' => 1000, 'CambioTurnoObservacion' => 1000],
                'patch' => ['CambioTurnoObservacion' => 'Coordinado con el jefe de servicio'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['CambioTurnoEstado' => 'APROBADO'], 'CambioTurnoEstado'],
                    [['VinculoLaboralReemplazanteId' => null], 'VinculoLaboralReemplazanteId'],   // el reemplazo exige reemplazante
                    [['TipoCambioTurnoId' => 999999], 'TipoCambioTurnoId'],
                    [['TurnoProgramadoId' => 999999], 'TurnoProgramadoId'],
                    [['TurnoProgramadoContraparteId' => 999999], 'TurnoProgramadoContraparteId'],
                    [['TurnoIdNuevo' => 999999], 'TurnoIdNuevo'],
                    [['VinculoLaboralSolicitanteId' => 999999], 'VinculoLaboralSolicitanteId'],
                    [['VinculoLaboralReemplazanteId' => 999999], 'VinculoLaboralReemplazanteId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['UsuarioRegistroId' => 999999], 'UsuarioRegistroId'],
                ],
            ],
        ];
    }
}
