<?php

namespace App\Console\Commands;

use App\Services\AutomatizacionService;
use Illuminate\Console\Command;

class MantenimientoDiario extends Command
{
    protected $signature = 'sistema:mantenimiento';

    protected $description = 'Vence compensaciones horarias (RIT Art. 17), marca turnos cumplidos y avisa de faltas reiteradas (RIT Art. 23)';

    public function handle(AutomatizacionService $automatizacion): int
    {
        $this->info('Compensaciones vencidas: '.$automatizacion->vencerCompensaciones());
        $this->info('Turnos marcados como cumplidos: '.$automatizacion->cumplirTurnos());
        $this->info('Avisos de faltas reiteradas: '.$automatizacion->detectarFaltasReiteradas());
        $this->info('Avisos de tardanza mensual: '.$automatizacion->detectarTardanzasAcumuladas());
        $this->info('Avisos de colegiatura vencida: '.$automatizacion->detectarColegiaturasVencidas());
        $this->info('Avisos de límite de edad: '.$automatizacion->detectarLimiteDeEdad());
        $this->info('Avisos de no reincorporación: '.$automatizacion->detectarNoReincorporacion());
        $this->info('Fraccionamientos aprobados por silencio: '.$automatizacion->aprobarFraccionamientosPorSilencio());
        $this->info('Compensaciones de días no laborables: '.$automatizacion->programarCompensacionDeDiasNoLaborables());

        return self::SUCCESS;
    }
}
