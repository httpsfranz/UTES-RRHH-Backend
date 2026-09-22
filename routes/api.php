<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MicroredController;
use App\Http\Controllers\Api\DispositivoMarcacionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('microredes', MicroredController::class)
    ->parameters(['microredes' => 'microred']);

// Cambiar 'dispositivos' por 'dispositivos-marcacion'
Route::apiResource('dispositivos-marcacion', DispositivoMarcacionController::class)
    ->parameters(['dispositivos-marcacion' => 'dispositivo']);