<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Asistencia
use App\Http\Controllers\Api\ConceptoJustificacionController;
use App\Http\Controllers\Api\EstadoAsistenciaController;

// Biometria
use App\Http\Controllers\Api\MetodoMarcacionController;
use App\Http\Controllers\Api\DispositivoMarcacionController;

// Compensaciones
use App\Http\Controllers\Api\ConceptoDescuentoController;
use App\Http\Controllers\Api\TipoCompensacionController;

// Configuracion
use App\Http\Controllers\Api\ParametroSistemaController;
use App\Http\Controllers\Api\TablaToleranciaController;
use App\Http\Controllers\Api\TipoJornadaController;

// Consolidacion
use App\Http\Controllers\Api\PeriodoAsistenciaController;

// Disciplina
use App\Http\Controllers\Api\TipoFaltaDisciplinariaController;

// Organizacion
use App\Http\Controllers\Api\MicroredController;
use App\Http\Controllers\Api\TipoEstablecimientoController;
use App\Http\Controllers\Api\TipoResponsabilidadController;

// Personal
use App\Http\Controllers\Api\ColegiaturaTipoController;
use App\Http\Controllers\Api\CondicionLaboralController;
use App\Http\Controllers\Api\GrupoOcupacionalController;
use App\Http\Controllers\Api\ProfesionController;
use App\Http\Controllers\Api\RegimenLaboralController;
use App\Http\Controllers\Api\TipoDocumentoIdentidadController;

// Programacion
use App\Http\Controllers\Api\TipoCambioTurnoController;
use App\Http\Controllers\Api\TipoPeriodoProgramacionController;

// Seguridad
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\PermisoController;
use App\Http\Controllers\Api\RolController;

// Solicitudes
use App\Http\Controllers\Api\TipoLicenciaController;
use App\Http\Controllers\Api\TipoPapeletaController;

// Soporte
use App\Http\Controllers\Api\CalendarioNoLaborableController;
use App\Http\Controllers\Api\DocumentoSustentoController;
use App\Http\Controllers\Api\LogIntegracionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* ---- Asistencia ---- */
Route::apiResource('microredes', MicroredController::class)
    ->parameters(['microredes' => 'microred']);

Route::apiResource('estados-asistencia', EstadoAsistenciaController::class)
    ->parameters([
        'estados-asistencia' => 'estadoAsistencia'
    ]);

Route::apiResource('conceptos-justificacion', ConceptoJustificacionController::class)
    ->parameters(['conceptos-justificacion' => 'concepto'])
    ->where(['concepto' => '[0-9]+']);

/* ---- Biometria ---- */
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
Route::apiResource('tipos-establecimiento', TipoEstablecimientoController::class)
    ->parameters(['tipos-establecimiento' => 'tipo']);

Route::apiResource('tipos-responsabilidad', TipoResponsabilidadController::class)
    ->parameters(['tipos-responsabilidad' => 'tipo']);

/* ---- Personal ---- */
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

Route::apiResource('roles', RolController::class)
    ->parameters(['roles' => 'rol']);

/* ---- Solicitudes ---- */
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
