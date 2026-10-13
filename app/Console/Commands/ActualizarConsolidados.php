<?php

namespace App\Console\Commands;

use App\Models\Consolidacion\PeriodoAsistencia;
use App\Services\AutomatizacionService;
use App\Services\ConsolidadoAsistenciaService;
use App\Support\HoraLocal;
use Illuminate\Console\Command;

class ActualizarConsolidados extends Command
{
    protected $signature = 'consolidado:actualizar';

    protected $description = 'Abre el periodo de asistencia del mes y regenera los consolidados de los periodos abiertos (no toca los conformes ni cerrados)';

    public function handle(AutomatizacionService $automatizacion, ConsolidadoAsistenciaService $consolidados): int
    {
        if ($automatizacion->abrirPeriodoDelMes()) {
            $this->info('Se abrio el periodo de asistencia del mes en curso.');
        }

        $periodos = PeriodoAsistencia::query()
            ->whereIn('PeriodoAsistenciaEstado', ['ABIERTO', 'EN_PROCESO'])
            ->whereDate('PeriodoAsistenciaFechaInicio', '<=', HoraLocal::hoy()->toDateString())
            ->get();
        foreach ($periodos as $periodo) {
            $resumen = $consolidados->generar($periodo);
            $this->info(sprintf('Periodo %d-%02d: %d generados, %d actualizados, %d omitidos.', $periodo->PeriodoAsistenciaAnio, $periodo->PeriodoAsistenciaMes, $resumen['generados'], $resumen['actualizados'], $resumen['omitidos']));
        }

        return self::SUCCESS;
    }
}
