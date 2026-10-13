<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Procesos automaticos. En produccion hace falta UN cron: `* * * * * php artisan schedule:run` (en desarrollo, `php artisan schedule:work`).
// El motor es idempotente: recalcular los ultimos dias solo reescribe lo que cambio y respeta lo digitado a mano.
Schedule::command('asistencia:calcular --dias=2')->hourly()->timezone('America/Lima')->withoutOverlapping();
Schedule::command('asistencia:calcular --dias=10')->dailyAt('02:00')->timezone('America/Lima')->withoutOverlapping();
Schedule::command('consolidado:actualizar')->dailyAt('02:30')->timezone('America/Lima')->withoutOverlapping();
Schedule::command('sistema:mantenimiento')->dailyAt('03:00')->timezone('America/Lima')->withoutOverlapping();
