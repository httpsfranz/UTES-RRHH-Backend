<?php

namespace App\Http\Requests\Concerns;

use App\Models\Vacaciones\PeriodoVacacional;
use App\Models\Vacaciones\RolVacacional;
use Illuminate\Validation\Validator;

/**
 * Reglas del Rol de Vacaciones que comparten registrarlo y reprogramarlo (RIT, Art. 68 a 74). Requiere ReglasDeNegocio.
 */
trait ReglasDeVacaciones
{
    /** Dias calendario que abarca [inicio, fin], ambos incluidos: el descanso vacacional cuenta dias calendario (RIT, Art. 70). */
    protected function diasCalendario(string $inicio, string $fin): int
    {
        return (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1;
    }

    /** Ultimo dia del descanso que empieza en $inicio y dura $dias dias calendario. */
    protected function fechaFinDelDescanso(string $inicio, int $dias): string
    {
        return date('Y-m-d', strtotime($inicio.' +'.($dias - 1).' days'));
    }

    /** La programacion vacacional tiene goces pendientes o aprobados: mientras existan no se modifica ni se anula. */
    protected function tieneGocesVigentes(RolVacacional $rol): bool
    {
        return $rol->goces()->whereIn('GoceVacacionalEstado', ['PENDIENTE', 'APROBADO'])->exists();
    }

    /**
     * Reglas de fondo de un descanso programado de $dias dias entre $inicio y $fin dentro de un periodo vacacional:
     * periodo abierto, vinculo vigente, periodo de asistencia no cerrado, sin cruzarse con otro descanso del mismo
     * trabajador, sin pasar los dias ganados y respetando el fraccionamiento (RIT, Art. 72).
     */
    protected function validarDescansoProgramado(Validator $validator, PeriodoVacacional $periodo, string $inicio, string $fin, int $dias, ?int $excluirRolId): void
    {
        if ($periodo->PeriodoVacacionalEstado !== 'ABIERTO') {
            $validator->errors()->add('PeriodoVacacionalId', 'El período vacacional está '.($periodo->PeriodoVacacionalEstado === 'CERRADO' ? 'cerrado' : 'anulado').': no admite programar descansos.');

            return;
        }
        $this->vinculoVigenteEn($validator, 'RolVacacionalFechaProgramada', $inicio, $fin, $periodo->VinculoLaboralId);
        $this->rechazaPeriodoCerrado($validator, 'RolVacacionalFechaProgramada', $inicio, $fin);

        $propios = RolVacacional::query()
            ->where('PeriodoVacacionalId', $periodo->PeriodoVacacionalId)
            ->whereIn('RolVacacionalEstado', ['PROGRAMADO', 'GOZADO'])
            ->when($excluirRolId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get();
        $programados = (float) $propios->sum('RolVacacionalDias') + $dias;
        if ($programados > (float) $periodo->PeriodoVacacionalDiasGanados) {
            $validator->errors()->add('RolVacacionalDias', "Con estos días se programarían {$programados} y el período solo tiene {$periodo->PeriodoVacacionalDiasGanados} días ganados (RIT, Art. 68).");
        }
        // El fraccionamiento: tramos de al menos 7 dias; hasta 7 dias en tramos menores (RIT, Art. 72).
        $sueltos = (float) $propios->where('RolVacacionalDias', '<', 7)->sum('RolVacacionalDias') + ($dias < 7 ? $dias : 0);
        if ($dias < 7 && $sueltos > 7) {
            $validator->errors()->add('RolVacacionalDias', "El descanso se toma en periodos de 7 días o más; solo hasta 7 días pueden fraccionarse en tramos menores (ya hay {$sueltos}) (RIT, Art. 72).");
        }

        $cruce = RolVacacional::query()
            ->whereHas('periodoVacacional.vinculoLaboral', fn ($v) => $v->where('TrabajadorId', $periodo->vinculoLaboral->TrabajadorId))
            ->whereIn('RolVacacionalEstado', ['PROGRAMADO', 'GOZADO'])
            ->when($excluirRolId, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereDate('RolVacacionalFechaProgramada', '<=', $fin)
            ->whereDate('RolVacacionalFechaFinProgramada', '>=', $inicio)
            ->exists();
        if ($cruce) {
            $validator->errors()->add('RolVacacionalFechaProgramada', 'El trabajador ya tiene vacaciones programadas que se cruzan con esas fechas.');
        }
    }
}
