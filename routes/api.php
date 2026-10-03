<?php

use App\Http\Controllers\Api\AjusteMarcacionController;
use App\Http\Controllers\Api\AsignacionHorarioController;
// Asistencia
use App\Http\Controllers\Api\AsistenciaDiariaController;
use App\Http\Controllers\Api\AuditoriaController;
// Biometria
use App\Http\Controllers\Api\AutorizacionMetodoController;
use App\Http\Controllers\Api\CalendarioNoLaborableController;
// Compensaciones
use App\Http\Controllers\Api\CambioTurnoController;
use App\Http\Controllers\Api\CargaAsistenciaManualController;
// Configuracion
use App\Http\Controllers\Api\CargaProgramacionController;
use App\Http\Controllers\Api\CargoController;
use App\Http\Controllers\Api\ColegiaturaController;
use App\Http\Controllers\Api\ColegiaturaTipoController;
use App\Http\Controllers\Api\CompensacionHorariaController;
use App\Http\Controllers\Api\ConceptoDescuentoController;
use App\Http\Controllers\Api\ConceptoJustificacionController;
use App\Http\Controllers\Api\CondicionLaboralController;
use App\Http\Controllers\Api\ConsentimientoBiometricoController;
use App\Http\Controllers\Api\ConsolidadoAsistenciaController;
use App\Http\Controllers\Api\ConstatacionDomiciliariaController;
use App\Http\Controllers\Api\DescansoMedicoController;
use App\Http\Controllers\Api\DetalleConsolidadoController;
use App\Http\Controllers\Api\DetalleLiquidacionController;
use App\Http\Controllers\Api\DispositivoMarcacionController;
use App\Http\Controllers\Api\DocumentoSustentoController;
use App\Http\Controllers\Api\EstablecimientoSaludController;
use App\Http\Controllers\Api\EstadoAsistenciaController;
use App\Http\Controllers\Api\ExpedientePadController;
// Consolidacion
use App\Http\Controllers\Api\GoceVacacionalController;
// Disciplina
use App\Http\Controllers\Api\GrupoOcupacionalController;
// Organizacion
use App\Http\Controllers\Api\HorarioController;
use App\Http\Controllers\Api\HorarioDetalleController;
use App\Http\Controllers\Api\InformeGuardiaComunitariaController;
use App\Http\Controllers\Api\JustificacionFaltaController;
// Personal
use App\Http\Controllers\Api\LicenciaController;
use App\Http\Controllers\Api\LiquidacionDescuentoController;
use App\Http\Controllers\Api\LogIntegracionController;
use App\Http\Controllers\Api\MarcacionController;
use App\Http\Controllers\Api\MetodoMarcacionController;
use App\Http\Controllers\Api\MicroredController;
// Programacion
use App\Http\Controllers\Api\MotivoPapeletaController;
use App\Http\Controllers\Api\NotificacionController;
// Seguridad
use App\Http\Controllers\Api\OcurrenciaPorteriaController;
use App\Http\Controllers\Api\PapeletaController;
use App\Http\Controllers\Api\ParametroJornadaController;
// Solicitudes
use App\Http\Controllers\Api\ParametroSistemaController;
use App\Http\Controllers\Api\PeriodoAsistenciaController;
// Soporte
use App\Http\Controllers\Api\PeriodoVacacionalController;
use App\Http\Controllers\Api\PermisoController;
use App\Http\Controllers\Api\PlantillaBiometricaController;
use App\Http\Controllers\Api\ProfesionController;
use App\Http\Controllers\Api\ProgramacionPeriodoController;
use App\Http\Controllers\Api\ProgramacionTrabajadorController;
use App\Http\Controllers\Api\RegimenLaboralController;
use App\Http\Controllers\Api\ResponsableEessController;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\RolPermisoController;
use App\Http\Controllers\Api\RolVacacionalController;
use App\Http\Controllers\Api\SesionAccesoController;
use App\Http\Controllers\Api\SupervisionInopinadaController;
use App\Http\Controllers\Api\TablaToleranciaController;
use App\Http\Controllers\Api\TipoCambioTurnoController;
use App\Http\Controllers\Api\TipoCompensacionController;
use App\Http\Controllers\Api\TipoDocumentoIdentidadController;
use App\Http\Controllers\Api\TipoEstablecimientoController;
use App\Http\Controllers\Api\TipoFaltaDisciplinariaController;
use App\Http\Controllers\Api\TipoJornadaController;
use App\Http\Controllers\Api\TipoLicenciaController;
use App\Http\Controllers\Api\TipoPapeletaController;
use App\Http\Controllers\Api\TipoPeriodoProgramacionController;
use App\Http\Controllers\Api\TipoResponsabilidadController;
use App\Http\Controllers\Api\TrabajadorController;
use App\Http\Controllers\Api\TramoToleranciaController;
use App\Http\Controllers\Api\TurnoController;
use App\Http\Controllers\Api\TurnoProgramadoController;
use App\Http\Controllers\Api\UsuarioAmbitoController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\UsuarioRolController;
use App\Http\Controllers\Api\VinculoLaboralController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* ---- Asistencia ---- */
Route::apiResource('estados-asistencia', EstadoAsistenciaController::class)
    ->parameters([
        'estados-asistencia' => 'estadoAsistencia',
    ]);

Route::apiResource('conceptos-justificacion', ConceptoJustificacionController::class)
    ->parameters(['conceptos-justificacion' => 'concepto'])
    ->where(['concepto' => '[0-9]+']);

/* ---- Biometria ---- */
Route::apiResource('plantillas-biometricas', PlantillaBiometricaController::class)
    ->parameters(['plantillas-biometricas' => 'plantilla']);

// Historial inmutable de consentimientos: solo se consulta y se registran eventos nuevos (acepta/revoca).
Route::apiResource('consentimientos-biometricos', ConsentimientoBiometricoController::class)
    ->parameters(['consentimientos-biometricos' => 'consentimiento'])
    ->only(['index', 'show', 'store']);

Route::apiResource('autorizaciones-metodo', AutorizacionMetodoController::class)
    ->parameters(['autorizaciones-metodo' => 'autorizacion']);

Route::apiResource('metodos-marcacion', MetodoMarcacionController::class)
    ->parameters(['metodos-marcacion' => 'metodo']);

Route::apiResource('dispositivos-marcacion', DispositivoMarcacionController::class)
    ->parameters(['dispositivos-marcacion' => 'dispositivo']);

/* ---- Compensaciones ---- */
Route::apiResource('conceptos-descuento', ConceptoDescuentoController::class)
    ->parameters(['conceptos-descuento' => 'concepto']);

Route::apiResource('tipos-compensacion', TipoCompensacionController::class)
    ->parameters(['tipos-compensacion' => 'tipo']);

/* ---- Configuracion ---- */
Route::apiResource('turnos', TurnoController::class)
    ->parameters(['turnos' => 'turno']);

// Grilla semanal de un horario. Las rutas extra van ANTES del apiResource para que no se confundan con {horario}.
Route::get('horarios/{horario}/detalle', [HorarioDetalleController::class, 'delHorario'])->whereNumber('horario');
Route::put('horarios/{horario}/detalle', [HorarioDetalleController::class, 'sincronizar'])->whereNumber('horario');

// Detalle de horario: sin Estado, DELETE elimina la fila.
Route::apiResource('horarios-detalle', HorarioDetalleController::class)
    ->parameters(['horarios-detalle' => 'detalle']);

Route::apiResource('horarios', HorarioController::class)
    ->parameters(['horarios' => 'horario']);

Route::apiResource('parametros-jornada', ParametroJornadaController::class)
    ->parameters(['parametros-jornada' => 'parametro']);

// Sin baja logica (la tabla no tiene Estado): DELETE elimina el tramo.
Route::apiResource('tramos-tolerancia', TramoToleranciaController::class)
    ->parameters(['tramos-tolerancia' => 'tramo']);

Route::apiResource('parametros-sistema', ParametroSistemaController::class)
    ->parameters(['parametros-sistema' => 'parametro']);

Route::apiResource('tablas-tolerancia', TablaToleranciaController::class)
    ->parameters(['tablas-tolerancia' => 'tabla']);

Route::apiResource('tipos-jornada', TipoJornadaController::class)
    ->parameters(['tipos-jornada' => 'tipo']);

/* ---- Consolidacion ---- */
// Sin destroy: el cierre de un periodo es una transicion de estado, no un DELETE.
Route::apiResource('periodos-asistencia', PeriodoAsistenciaController::class)
    ->parameters(['periodos-asistencia' => 'periodo'])
    ->except(['destroy']);

/* ---- Disciplina ---- */
Route::apiResource('tipos-falta-disciplinaria', TipoFaltaDisciplinariaController::class)
    ->parameters(['tipos-falta-disciplinaria' => 'tipo']);

/* ---- Organizacion ---- */
Route::apiResource('microredes', MicroredController::class)
    ->parameters(['microredes' => 'microred']);

Route::apiResource('establecimientos', EstablecimientoSaludController::class)
    ->parameters(['establecimientos' => 'establecimiento']);

Route::apiResource('tipos-establecimiento', TipoEstablecimientoController::class)
    ->parameters(['tipos-establecimiento' => 'tipo']);

Route::apiResource('tipos-responsabilidad', TipoResponsabilidadController::class)
    ->parameters(['tipos-responsabilidad' => 'tipo']);

/* ---- Personal ---- */
Route::apiResource('vinculos-laborales', VinculoLaboralController::class)
    ->parameters(['vinculos-laborales' => 'vinculo']);

Route::apiResource('colegiaturas', ColegiaturaController::class)
    ->parameters(['colegiaturas' => 'colegiatura']);

Route::apiResource('trabajadores', TrabajadorController::class)
    ->parameters(['trabajadores' => 'trabajador']);

Route::apiResource('cargos', CargoController::class)
    ->parameters(['cargos' => 'cargo']);

Route::apiResource('profesiones', ProfesionController::class)
    ->parameters(['profesiones' => 'profesion']);

Route::apiResource('tipos-colegiatura', ColegiaturaTipoController::class)
    ->parameters(['tipos-colegiatura' => 'tipo']);

Route::apiResource('condiciones-laborales', CondicionLaboralController::class)
    ->parameters(['condiciones-laborales' => 'condicion']);

Route::apiResource('grupos-ocupacionales', GrupoOcupacionalController::class)
    ->parameters(['grupos-ocupacionales' => 'grupo']);

Route::apiResource('regimenes-laborales', RegimenLaboralController::class)
    ->parameters(['regimenes-laborales' => 'regimen']);

Route::apiResource('tipos-documento-identidad', TipoDocumentoIdentidadController::class)
    ->parameters(['tipos-documento-identidad' => 'tipo']);

/* ---- Programacion ---- */
Route::apiResource('tipos-cambio-turno', TipoCambioTurnoController::class)
    ->parameters(['tipos-cambio-turno' => 'tipo']);

Route::apiResource('tipos-periodo-programacion', TipoPeriodoProgramacionController::class)
    ->parameters(['tipos-periodo-programacion' => 'tipo']);

/* ---- Seguridad ---- */
// Solo lectura: nadie crea/edita/borra un registro de auditoria via API.
Route::apiResource('auditoria', AuditoriaController::class)
    ->parameters(['auditoria' => 'auditoria'])
    ->only(['index', 'show']);

Route::apiResource('permisos', PermisoController::class)
    ->parameters(['permisos' => 'permiso']);

Route::apiResource('usuarios', UsuarioController::class)
    ->parameters(['usuarios' => 'usuario']);

// Asignacion de permisos de un rol. Las rutas extra van ANTES del apiResource para que no se confundan con {rol}.
Route::get('roles/{rol}/permisos', [RolPermisoController::class, 'delRol'])->whereNumber('rol');
Route::put('roles/{rol}/permisos', [RolPermisoController::class, 'sincronizar'])->whereNumber('rol');

// Tabla puente: DELETE quita la fila (el permiso deja de estar asignado al rol).
Route::apiResource('roles-permisos', RolPermisoController::class)
    ->parameters(['roles-permisos' => 'asignacion']);

Route::apiResource('roles', RolController::class)
    ->parameters(['roles' => 'rol']);

/* ---- Solicitudes ---- */
// El estado es un ciclo de vida (REGISTRADO/ATENDIDO/ANULADO): DELETE anula la ocurrencia.
Route::apiResource('ocurrencias-porteria', OcurrenciaPorteriaController::class)
    ->parameters(['ocurrencias-porteria' => 'ocurrencia']);

Route::apiResource('motivos-papeleta', MotivoPapeletaController::class)
    ->parameters(['motivos-papeleta' => 'motivo']);

Route::apiResource('tipos-licencia', TipoLicenciaController::class)
    ->parameters(['tipos-licencia' => 'tipo']);

Route::apiResource('tipos-papeleta', TipoPapeletaController::class)
    ->parameters(['tipos-papeleta' => 'tipo']);

/* ---- Soporte ---- */
Route::apiResource('calendario-no-laborable', CalendarioNoLaborableController::class)
    ->parameters(['calendario-no-laborable' => 'dia']);

Route::apiResource('documentos-sustento', DocumentoSustentoController::class)
    ->parameters(['documentos-sustento' => 'documento']);

// Solo lectura: los logs de integracion los escriben los procesos internos, no un cliente HTTP.
Route::apiResource('logs-integracion', LogIntegracionController::class)
    ->parameters(['logs-integracion' => 'log'])
    ->only(['index', 'show']);

/* ---- Nivel 3 / lote A: Asistencia y soporte ---- */
Route::post('justificaciones-falta/{justificacion}/aprobar', [JustificacionFaltaController::class, 'aprobar'])->whereNumber('justificacion');
Route::post('justificaciones-falta/{justificacion}/rechazar', [JustificacionFaltaController::class, 'rechazar'])->whereNumber('justificacion');
Route::apiResource('justificaciones-falta', JustificacionFaltaController::class)
    ->parameters(['justificaciones-falta' => 'justificacion']);

Route::apiResource('marcaciones', MarcacionController::class)
    ->parameters(['marcaciones' => 'marcacion']);

Route::apiResource('asistencia-diaria', AsistenciaDiariaController::class)
    ->parameters(['asistencia-diaria' => 'asistencia']);

Route::apiResource('cargas-asistencia-manual', CargaAsistenciaManualController::class)
    ->parameters(['cargas-asistencia-manual' => 'carga']);

Route::apiResource('notificaciones', NotificacionController::class)
    ->parameters(['notificaciones' => 'notificacion']);

/* ---- Nivel 3 / lote B: Solicitudes ---- */
Route::post('papeletas/{papeleta}/aprobar', [PapeletaController::class, 'aprobar'])->whereNumber('papeleta');
Route::post('papeletas/{papeleta}/rechazar', [PapeletaController::class, 'rechazar'])->whereNumber('papeleta');
Route::apiResource('papeletas', PapeletaController::class)
    ->parameters(['papeletas' => 'papeleta']);

Route::post('licencias/{licencia}/aprobar', [LicenciaController::class, 'aprobar'])->whereNumber('licencia');
Route::post('licencias/{licencia}/rechazar', [LicenciaController::class, 'rechazar'])->whereNumber('licencia');
Route::apiResource('licencias', LicenciaController::class)
    ->parameters(['licencias' => 'licencia']);

Route::post('descansos-medicos/{descanso}/aprobar', [DescansoMedicoController::class, 'aprobar'])->whereNumber('descanso');
Route::post('descansos-medicos/{descanso}/rechazar', [DescansoMedicoController::class, 'rechazar'])->whereNumber('descanso');
Route::apiResource('descansos-medicos', DescansoMedicoController::class)
    ->parameters(['descansos-medicos' => 'descanso']);

Route::apiResource('constataciones-domiciliarias', ConstatacionDomiciliariaController::class)
    ->parameters(['constataciones-domiciliarias' => 'constatacion']);

/* ---- Nivel 3 / lote C: Programacion, personal y seguridad ---- */
Route::post('programaciones-periodo/{programacion}/publicar', [ProgramacionPeriodoController::class, 'publicar'])->whereNumber('programacion');
Route::post('programaciones-periodo/{programacion}/cerrar', [ProgramacionPeriodoController::class, 'cerrar'])->whereNumber('programacion');
Route::apiResource('programaciones-periodo', ProgramacionPeriodoController::class)
    ->parameters(['programaciones-periodo' => 'programacion']);

Route::apiResource('cargas-programacion', CargaProgramacionController::class)
    ->parameters(['cargas-programacion' => 'carga']);

Route::post('informes-guardia-comunitaria/{informe}/aprobar', [InformeGuardiaComunitariaController::class, 'aprobar'])->whereNumber('informe');
Route::post('informes-guardia-comunitaria/{informe}/rechazar', [InformeGuardiaComunitariaController::class, 'rechazar'])->whereNumber('informe');
Route::apiResource('informes-guardia-comunitaria', InformeGuardiaComunitariaController::class)
    ->parameters(['informes-guardia-comunitaria' => 'informe']);

Route::apiResource('asignaciones-horario', AsignacionHorarioController::class)
    ->parameters(['asignaciones-horario' => 'asignacion']);

Route::apiResource('responsables-eess', ResponsableEessController::class)
    ->parameters(['responsables-eess' => 'responsable']);

Route::apiResource('usuarios-roles', UsuarioRolController::class)
    ->parameters(['usuarios-roles' => 'asignacion']);

Route::apiResource('usuarios-ambitos', UsuarioAmbitoController::class)
    ->parameters(['usuarios-ambitos' => 'ambito']);

Route::apiResource('sesiones-acceso', SesionAccesoController::class)
    ->parameters(['sesiones-acceso' => 'sesion'])
    ->only(['index', 'show']);

/* ---- Nivel 3 / lote D: Consolidacion, compensaciones, vacaciones y disciplina ---- */
Route::post('consolidados-asistencia/generar', [ConsolidadoAsistenciaController::class, 'generar']);
Route::apiResource('consolidados-asistencia', ConsolidadoAsistenciaController::class)
    ->parameters(['consolidados-asistencia' => 'consolidado']);

Route::post('compensaciones-horarias/{compensacion}/aprobar', [CompensacionHorariaController::class, 'aprobar'])->whereNumber('compensacion');
Route::post('compensaciones-horarias/{compensacion}/devolver', [CompensacionHorariaController::class, 'devolver'])->whereNumber('compensacion');
Route::apiResource('compensaciones-horarias', CompensacionHorariaController::class)
    ->parameters(['compensaciones-horarias' => 'compensacion']);

Route::apiResource('periodos-vacacionales', PeriodoVacacionalController::class)
    ->parameters(['periodos-vacacionales' => 'periodo']);

Route::apiResource('expedientes-pad', ExpedientePadController::class)
    ->parameters(['expedientes-pad' => 'expediente']);

Route::apiResource('supervisiones-inopinadas', SupervisionInopinadaController::class)
    ->parameters(['supervisiones-inopinadas' => 'supervision']);

/* ---- Nivel 4: Programacion de trabajadores, ajustes, detalle de consolidado, liquidacion y rol vacacional ---- */
Route::apiResource('programaciones-trabajador', ProgramacionTrabajadorController::class)
    ->parameters(['programaciones-trabajador' => 'programacionTrabajador']);

Route::post('ajustes-marcacion/{ajuste}/aprobar', [AjusteMarcacionController::class, 'aprobar'])->whereNumber('ajuste');
Route::post('ajustes-marcacion/{ajuste}/rechazar', [AjusteMarcacionController::class, 'rechazar'])->whereNumber('ajuste');
Route::apiResource('ajustes-marcacion', AjusteMarcacionController::class)
    ->parameters(['ajustes-marcacion' => 'ajuste']);

Route::apiResource('detalles-consolidado', DetalleConsolidadoController::class)
    ->parameters(['detalles-consolidado' => 'detalle']);

Route::post('liquidaciones-descuento/{liquidacion}/aprobar', [LiquidacionDescuentoController::class, 'aprobar'])->whereNumber('liquidacion');
Route::post('liquidaciones-descuento/{liquidacion}/remitir', [LiquidacionDescuentoController::class, 'remitir'])->whereNumber('liquidacion');
Route::apiResource('liquidaciones-descuento', LiquidacionDescuentoController::class)
    ->parameters(['liquidaciones-descuento' => 'liquidacion'])
    ->only(['index', 'show', 'store', 'destroy']);

Route::post('roles-vacacionales/{rolVacacional}/reprogramar', [RolVacacionalController::class, 'reprogramar'])->whereNumber('rolVacacional');
Route::apiResource('roles-vacacionales', RolVacacionalController::class)
    ->parameters(['roles-vacacionales' => 'rolVacacional']);

/* ---- Nivel 5: Turnos programados, detalle de liquidacion y goce vacacional ---- */
Route::post('turnos-programados/{turnoProgramado}/cumplir', [TurnoProgramadoController::class, 'cumplir'])->whereNumber('turnoProgramado');
Route::apiResource('turnos-programados', TurnoProgramadoController::class)
    ->parameters(['turnos-programados' => 'turnoProgramado']);

Route::apiResource('detalles-liquidacion', DetalleLiquidacionController::class)
    ->parameters(['detalles-liquidacion' => 'detalle']);

Route::post('goces-vacacionales/{goce}/aprobar', [GoceVacacionalController::class, 'aprobar'])->whereNumber('goce');
Route::post('goces-vacacionales/{goce}/rechazar', [GoceVacacionalController::class, 'rechazar'])->whereNumber('goce');
Route::apiResource('goces-vacacionales', GoceVacacionalController::class)
    ->parameters(['goces-vacacionales' => 'goce']);

/* ---- Nivel 6: Cambio de turno ---- */
Route::post('cambios-turno/{cambio}/aprobar', [CambioTurnoController::class, 'aprobar'])->whereNumber('cambio');
Route::post('cambios-turno/{cambio}/rechazar', [CambioTurnoController::class, 'rechazar'])->whereNumber('cambio');
Route::apiResource('cambios-turno', CambioTurnoController::class)
    ->parameters(['cambios-turno' => 'cambio']);
