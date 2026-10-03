<?php

namespace App\Services;

use App\Models\Compensaciones\CompensacionHoraria;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una compensacion horaria: PENDIENTE -> APROBADO -> CONSUMIDO (o VENCIDO / ANULADO).
 * RIT, Art. 17: no procede la compensacion por sobretiempo cuando no fue previamente autorizada por el jefe inmediato.
 */
class CompensacionHorariaService
{
    /** Aprobar exige que el trabajo haya sido autorizado previamente; quien aprueba queda como quien autoriza. */
    public function aprobar(CompensacionHoraria $compensacion, int $usuarioId, ?string $nota = null): CompensacionHoraria
    {
        return DB::transaction(function () use ($compensacion, $usuarioId, $nota) {
            if ($compensacion->CompensacionHorariaEstado !== 'PENDIENTE') {
                throw new DomainException('Solo se pueden aprobar compensaciones pendientes.');
            }
            if (! $compensacion->CompensacionHorariaAutorizadoPreviamente) {
                throw new DomainException('No procede la compensación: el trabajo no fue autorizado previamente por el jefe inmediato (RIT, Art. 17).');
            }

            $cambios = ['CompensacionHorariaEstado' => 'APROBADO', 'CompensacionHorariaAutorizadoPor' => $usuarioId];
            if ($nota) {
                $actual = (string) $compensacion->CompensacionHorariaObservacion;
                $cambios['CompensacionHorariaObservacion'] = mb_substr(trim(($actual === '' ? '' : $actual."\n")."Aprobación: {$nota}"), 0, 500);
            }
            $compensacion->update($cambios);

            return $compensacion->fresh();
        });
    }

    /** Registra horas devueltas; al completar las generadas, la compensacion queda CONSUMIDA. */
    public function devolver(CompensacionHoraria $compensacion, float $horas): CompensacionHoraria
    {
        return DB::transaction(function () use ($compensacion, $horas) {
            if ($compensacion->CompensacionHorariaEstado !== 'APROBADO') {
                throw new DomainException('Solo se devuelven horas de una compensación aprobada.');
            }
            $pendientes = round($compensacion->CompensacionHorariaHorasGeneradas - $compensacion->CompensacionHorariaHorasDevueltas, 2);
            if ($horas > $pendientes) {
                throw new DomainException("Solo quedan {$pendientes} horas por devolver.");
            }

            $devueltas = round($compensacion->CompensacionHorariaHorasDevueltas + $horas, 2);
            $compensacion->update([
                'CompensacionHorariaHorasDevueltas' => $devueltas,
                'CompensacionHorariaEstado' => $devueltas >= (float) $compensacion->CompensacionHorariaHorasGeneradas ? 'CONSUMIDO' : 'APROBADO',
            ]);

            return $compensacion->fresh();
        });
    }

    /** Anular es idempotente; una compensacion con horas ya devueltas se conserva como constancia. */
    public function anular(CompensacionHoraria $compensacion): CompensacionHoraria
    {
        if ($compensacion->CompensacionHorariaEstado === 'ANULADO') {
            return $compensacion;
        }
        if ($compensacion->CompensacionHorariaHorasDevueltas > 0) {
            throw new DomainException('No se puede anular: ya se devolvieron horas de esta compensación.');
        }
        $compensacion->update(['CompensacionHorariaEstado' => 'ANULADO']);

        return $compensacion->fresh();
    }
}
