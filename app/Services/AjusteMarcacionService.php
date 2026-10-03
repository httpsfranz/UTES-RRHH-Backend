<?php

namespace App\Services;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Asistencia\Marcacion;
use App\Support\PeriodosDeAsistencia;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una solicitud de ajuste de marcacion: PENDIENTE -> APROBADO | RECHAZADO | ANULADO. Al aprobarla se
 * aplica a la marcacion (nueva fecha y hora, o invalidada si la solicitud no trae fecha nueva) en la misma transaccion.
 * Un ajuste aprobado ya modifico la asistencia: no se anula, se corrige con otra solicitud.
 */
class AjusteMarcacionService
{
    public function __construct(private readonly SolicitudService $solicitudes) {}

    public function aprobar(AjusteMarcacion $ajuste, int $usuarioId, ?string $nota = null): AjusteMarcacion
    {
        return DB::transaction(function () use ($ajuste, $usuarioId, $nota) {
            if ($ajuste->AjusteMarcacionEstado !== 'PENDIENTE') {
                throw new DomainException('Solo se pueden aprobar solicitudes pendientes.');
            }
            $this->aplicar($ajuste);

            return $this->solicitudes->aprobar($ajuste, $usuarioId, $nota);
        });
    }

    public function rechazar(AjusteMarcacion $ajuste, int $usuarioId, string $motivo): AjusteMarcacion
    {
        return $this->solicitudes->rechazar($ajuste, $usuarioId, $motivo);
    }

    public function anular(AjusteMarcacion $ajuste): AjusteMarcacion
    {
        if ($ajuste->AjusteMarcacionEstado === 'APROBADO') {
            throw new DomainException('Un ajuste aprobado ya se aplicó a la marcación: para corregirlo registra otra solicitud.');
        }

        return $this->solicitudes->anular($ajuste);
    }

    private function aplicar(AjusteMarcacion $ajuste): void
    {
        $marcacion = $ajuste->marcacion()->firstOrFail();
        $actual = $marcacion->MarcacionFechaHora->format('Y-m-d H:i:s');

        if (! $marcacion->MarcacionEsValida) {
            throw new DomainException('La marcación ya está invalidada: no admite ajustes.');
        }
        if ($ajuste->AjusteMarcacionFechaHoraAnterior?->format('Y-m-d H:i:s') !== $actual) {
            throw new DomainException('La marcación cambió después de registrar el ajuste: registra una solicitud nueva.');
        }
        $nueva = $ajuste->AjusteMarcacionFechaHoraNueva?->format('Y-m-d H:i:s');
        if (PeriodosDeAsistencia::cerradoEn(substr($actual, 0, 10), $nueva ? substr($nueva, 0, 10) : null)) {
            throw new DomainException('El período de asistencia de esa fecha ya está cerrado: no se puede ajustar la marcación.');
        }
        if ($nueva !== null && Marcacion::query()->where('VinculoLaboralId', $marcacion->VinculoLaboralId)
            ->where('MarcacionFechaHora', $nueva)->where('MarcacionTipo', $marcacion->MarcacionTipo)->whereKeyNot($marcacion->MarcacionId)->exists()) {
            throw new DomainException('Ya existe una marcación del mismo tipo para ese trabajador en esa fecha y hora.');
        }

        $marcacion->update($nueva !== null ? ['MarcacionFechaHora' => $nueva] : ['MarcacionEsValida' => false]);
    }
}
