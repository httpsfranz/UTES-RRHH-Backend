<?php

namespace App\Console\Commands;

use App\Services\CalculoAsistenciaService;
use App\Support\HoraLocal;
use Illuminate\Console\Command;

class CalcularAsistenciaAutomatica extends Command
{
    protected $signature = 'asistencia:calcular {--dias=2 : Cuantos dias hacia atras (incluido hoy) se recalculan}';

    protected $description = 'Calcula la asistencia diaria de los ultimos dias con el motor (respeta lo digitado a mano y los periodos cerrados)';

    public function handle(CalculoAsistenciaService $motor): int
    {
        $dias = max(1, min((int) $this->option('dias'), 62));
        $hoy = HoraLocal::hoy();
        $resumen = $motor->calcular($hoy->subDays($dias - 1)->toDateString(), $hoy->toDateString());

        $this->info(sprintf('Asistencia calculada (%d dias): %d evaluados, %d creados, %d actualizados, %d sin cambios.', $dias, $resumen['evaluados'], $resumen['creados'], $resumen['actualizados'], $resumen['sin_cambios']));

        return self::SUCCESS;
    }
}
