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

        return self::SUCCESS;
    }
}
