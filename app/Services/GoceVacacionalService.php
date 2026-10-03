<?php

namespace App\Services;

use App\Models\Vacaciones\GoceVacacional;
use App\Support\HoraLocal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una solicitud de goce vacacional: PENDIENTE -> APROBADO | RECHAZADO | ANULADO. Aprobarla descuenta sus
 * dias de los disponibles del periodo vacacional y, cuando los goces aprobados cubren todo el descanso programado, este
 * queda GOZADO. Anular un goce aprobado que aun no empezo devuelve los dias y reabre la programacion.
 */
class GoceVacacionalService
{
    public function __construct(private readonly SolicitudService $solicitudes) {}

    public function aprobar(GoceVacacional $goce, int $usuarioId, ?string $nota = null): GoceVacacional
    {
        return DB::transaction(function () use ($goce, $usuarioId, $nota) {
            if ($goce->GoceVacacionalEstado !== 'PENDIENTE') {
                throw new DomainException('Solo se pueden aprobar solicitudes pendientes.');
            }
            $rol = $goce->rolVacacional()->with('periodoVacacional')->firstOrFail();
            $periodo = $rol->periodoVacacional;
            if ($rol->RolVacacionalEstado !== 'PROGRAMADO' || $periodo->PeriodoVacacionalEstado !== 'ABIERTO') {
                throw new DomainException('La programación o el período vacacional ya no están vigentes: no se puede aprobar el goce.');
            }
            $dias = (float) $goce->GoceVacacionalDias;
            if ($dias > (float) $periodo->PeriodoVacacionalDiasDisponibles) {
                throw new DomainException("El período vacacional solo tiene {$periodo->PeriodoVacacionalDiasDisponibles} días disponibles.");
            }

            $periodo->update(['PeriodoVacacionalDiasDisponibles' => round((float) $periodo->PeriodoVacacionalDiasDisponibles - $dias, 2)]);
            $aprobado = $this->solicitudes->aprobar($goce, $usuarioId, $nota);

            $gozados = (float) $rol->goces()->where('GoceVacacionalEstado', 'APROBADO')->sum('GoceVacacionalDias');
            if ($gozados >= (float) $rol->RolVacacionalDias) {
                $rol->update(['RolVacacionalEstado' => 'GOZADO']);
            }

            return $aprobado;
        });
    }

    public function rechazar(GoceVacacional $goce, int $usuarioId, string $motivo): GoceVacacional
    {
        return $this->solicitudes->rechazar($goce, $usuarioId, $motivo);
    }

    /** Un goce aprobado solo se anula si no empezo: se devuelven los dias al periodo y la programacion vuelve a PROGRAMADO. */
    public function anular(GoceVacacional $goce): GoceVacacional
    {
        return DB::transaction(function () use ($goce) {
            if ($goce->GoceVacacionalEstado === 'APROBADO') {
                if ($goce->GoceVacacionalFechaInicio->lte(HoraLocal::hoy())) {
                    throw new DomainException('El goce aprobado ya empezó: no se puede anular.');
                }
                $rol = $goce->rolVacacional()->with('periodoVacacional')->firstOrFail();
                $periodo = $rol->periodoVacacional;
                $disponibles = round((float) $periodo->PeriodoVacacionalDiasDisponibles + (float) $goce->GoceVacacionalDias, 2);
                $periodo->update(['PeriodoVacacionalDiasDisponibles' => min($disponibles, (float) $periodo->PeriodoVacacionalDiasGanados)]);
                if ($rol->RolVacacionalEstado === 'GOZADO') {
                    $rol->update(['RolVacacionalEstado' => 'PROGRAMADO']);
                }
            }

            return $this->solicitudes->anular($goce);
        });
    }
}
