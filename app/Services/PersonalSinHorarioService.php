<?php

namespace App\Services;

use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Personal con vinculo vigente al que todavia nadie le dijo cuando trabaja.
 *
 * RRHH registra al trabajador cuando ingresa (RIT, Art. 8), pero quien define su horario es el jefe del establecimiento
 * (RIT, Art. 16: programacion hasta el dia 05, revision y aprobacion del jefe). Por eso el alta no exige horario: queda
 * como pendiente visible hasta que tenga un horario fijo vigente o una programacion (en borrador o publicada) que toque
 * el mes actual o el siguiente. Mientras tanto el motor no le exige dias y no le genera faltas.
 */
class PersonalSinHorarioService
{
    /**
     * Vinculos vigentes hoy sin horario ni programacion (vl.VinculoLaboralId, vl.TrabajadorId, vl.EessId).
     *
     * @param  list<int>|null  $eess  establecimientos permitidos (null = toda la Red)
     */
    public function vinculos(?array $eess, ?CarbonImmutable $hoy = null): Builder
    {
        $hoy ??= HoraLocal::hoy();
        $dia = $hoy->toDateString();
        $desde = $hoy->startOfMonth()->toDateString();
        $hasta = $hoy->startOfMonth()->addMonth()->endOfMonth()->toDateString();

        return DB::table('Personal.VinculoLaboral as vl')
            ->where('vl.VinculoLaboralEstado', 1)
            ->whereDate('vl.VinculoLaboralFechaInicio', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('vl.VinculoLaboralFechaFin')->orWhereDate('vl.VinculoLaboralFechaFin', '>=', $dia))
            ->when($eess !== null, fn ($q) => $q->whereIn('vl.EessId', $eess === [] ? [0] : $eess))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('Personal.AsignacionHorario as ah')
                ->whereColumn('ah.VinculoLaboralId', 'vl.VinculoLaboralId')->where('ah.AsignacionHorarioEstado', 1)
                ->where(fn ($f) => $f->whereNull('ah.AsignacionHorarioFechaFin')->orWhereDate('ah.AsignacionHorarioFechaFin', '>=', $dia)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('Programacion.ProgramacionTrabajador as pt')
                ->join('Programacion.ProgramacionPeriodo as pp', 'pp.ProgramacionPeriodoId', '=', 'pt.ProgramacionPeriodoId')
                ->whereColumn('pt.VinculoLaboralId', 'vl.VinculoLaboralId')->where('pt.ProgramacionTrabajadorEstado', '<>', 'ANULADA')
                ->whereDate('pp.ProgramacionPeriodoFechaInicio', '<=', $hasta)->whereDate('pp.ProgramacionPeriodoFechaFin', '>=', $desde))
            ->select('vl.VinculoLaboralId', 'vl.TrabajadorId', 'vl.EessId');
    }

    /** @param  list<int>|null  $eess */
    public function cantidad(?array $eess): int
    {
        return $this->vinculos($eess)->count();
    }
}
