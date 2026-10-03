<?php

namespace App\Services;

use App\Models\Vacaciones\RolVacacional;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una programacion del Rol de Vacaciones: PROGRAMADO -> GOZADO (cuando se aprueban los goces que la
 * cubren), o REPROGRAMADO / ANULADO mientras no tenga goces vigentes (RIT, Art. 70 y 71).
 */
class RolVacacionalService
{
    /** Anular es idempotente; una programacion gozada o reprogramada es historia y se conserva. */
    public function anular(RolVacacional $rol): RolVacacional
    {
        if (in_array($rol->RolVacacionalEstado, ['GOZADO', 'REPROGRAMADO'], true)) {
            throw new DomainException('Una programación '.($rol->RolVacacionalEstado === 'GOZADO' ? 'gozada' : 'reprogramada').' se conserva como historia: no se anula.');
        }
        if ($rol->RolVacacionalEstado === 'ANULADO') {
            return $rol;
        }
        $this->exigirSinGocesVigentes($rol, 'anularla');
        $rol->update(['RolVacacionalEstado' => 'ANULADO']);

        return $rol->fresh();
    }

    /**
     * Mueve el descanso a otra fecha de inicio conservando los dias: la programacion actual queda REPROGRAMADO y se crea una
     * nueva PROGRAMADO, que es la que se devuelve.
     */
    public function reprogramar(RolVacacional $rol, string $nuevaFecha): RolVacacional
    {
        if ($rol->RolVacacionalEstado !== 'PROGRAMADO') {
            throw new DomainException('Solo se reprograma una programación vacacional en estado PROGRAMADO.');
        }
        $this->exigirSinGocesVigentes($rol, 'reprogramarla');

        return DB::transaction(function () use ($rol, $nuevaFecha) {
            $dias = (int) $rol->RolVacacionalDias;
            $nuevo = RolVacacional::create([
                'PeriodoVacacionalId' => $rol->PeriodoVacacionalId,
                'RolVacacionalFechaProgramada' => $nuevaFecha,
                'RolVacacionalFechaFinProgramada' => date('Y-m-d', strtotime($nuevaFecha.' +'.($dias - 1).' days')),
                'RolVacacionalDias' => $dias,
                'RolVacacionalEstado' => 'PROGRAMADO',
            ]);
            $rol->update(['RolVacacionalEstado' => 'REPROGRAMADO']);

            return $nuevo->fresh();
        });
    }

    private function exigirSinGocesVigentes(RolVacacional $rol, string $accion): void
    {
        if ($rol->goces()->whereIn('GoceVacacionalEstado', ['PENDIENTE', 'APROBADO'])->exists()) {
            throw new DomainException("La programación tiene goces solicitados: anúlalos antes de {$accion}.");
        }
    }
}
