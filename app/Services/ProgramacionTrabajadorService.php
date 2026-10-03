<?php

namespace App\Services;

use App\Models\Programacion\ProgramacionTrabajador;
use Illuminate\Support\Facades\DB;

/**
 * Programacion de un trabajador dentro de un periodo. Retirarlo de una programacion en borrador retira tambien sus
 * turnos; una programacion publicada no se modifica (RIT, Art. 16).
 */
class ProgramacionTrabajadorService
{
    public function eliminar(ProgramacionTrabajador $programacionTrabajador): void
    {
        ProgramacionPeriodoService::exigirBorrador($programacionTrabajador->periodo);

        DB::transaction(function () use ($programacionTrabajador) {
            $programacionTrabajador->turnos()->delete();
            $programacionTrabajador->delete();
        });
    }
}
