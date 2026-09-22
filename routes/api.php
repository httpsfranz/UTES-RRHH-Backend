<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ConceptoJustificacionController;
use App\Http\Controllers\Api\MicroredController;
use App\Http\Controllers\Api\DispositivoMarcacionController;
use App\Http\Controllers\Api\EstadoAsistenciaController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('microredes', MicroredController::class)
    ->parameters(['microredes' => 'microred']);

Route::apiResource('dispositivos-marcacion', DispositivoMarcacionController::class)
    ->parameters(['dispositivos-marcacion' => 'dispositivo']);

Route::apiResource('estados-asistencia', EstadoAsistenciaController::class)
    ->parameters([
        'estados-asistencia' => 'estadoAsistencia'
    ]);

Route::apiResource('conceptos-justificacion', ConceptoJustificacionController::class)
    ->parameters(['conceptos-justificacion' => 'concepto'])
    ->where(['concepto' => '[0-9]+']);