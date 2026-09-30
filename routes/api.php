<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\CalendarioNoLaborableController;
// Asistencia
use App\Http\Controllers\Api\ColegiaturaTipoController;
use App\Http\Controllers\Api\ConceptoDescuentoController;
// Biometria
use App\Http\Controllers\Api\ConceptoJustificacionController;
use App\Http\Controllers\Api\CondicionLaboralController;
// Compensaciones
use App\Http\Controllers\Api\DispositivoMarcacionController;
use App\Http\Controllers\Api\DocumentoSustentoController;
// Configuracion
use App\Http\Controllers\Api\EstablecimientoSaludController;
use App\Http\Controllers\Api\EstadoAsistenciaController;
use App\Http\Controllers\Api\GrupoOcupacionalController;
// Consolidacion
use App\Http\Controllers\Api\LogIntegracionController;
// Disciplina
use App\Http\Controllers\Api\MetodoMarcacionController;
// Organizacion
use App\Http\Controllers\Api\MicroredController;
use App\Http\Controllers\Api\ParametroSistemaController;
use App\Http\Controllers\Api\PeriodoAsistenciaController;
use App\Http\Controllers\Api\PermisoController;
// Personal
use App\Http\Controllers\Api\ProfesionController;
use App\Http\Controllers\Api\RegimenLaboralController;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\TablaToleranciaController;
use App\Http\Controllers\Api\TipoCambioTurnoController;
use App\Http\Controllers\Api\TipoCompensacionController;
// Programacion
use App\Http\Controllers\Api\TipoDocumentoIdentidadController;
use App\Http\Controllers\Api\TipoEstablecimientoController;
// Seguridad
use App\Http\Controllers\Api\TipoFaltaDisciplinariaController;
use App\Http\Controllers\Api\TipoJornadaController;
use App\Http\Controllers\Api\TipoLicenciaController;
// Solicitudes
use App\Http\Controllers\Api\TipoPapeletaController;
use App\Http\Controllers\Api\TipoPeriodoProgramacionController;
// Soporte
use App\Http\Controllers\Api\TipoResponsabilidadController;
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
// Solo lectura (catalogo de consulta para selects); el CRUD completo es el modulo M01.
Route::apiResource('microredes', MicroredController::class)
    ->parameters(['microredes' => 'microred']);

Route::apiResource('establecimientos', EstablecimientoSaludController::class)
    ->parameters(['establecimientos' => 'establecimiento'])
    ->only(['index', 'show']);

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
