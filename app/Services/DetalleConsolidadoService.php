<?php

namespace App\Services;

use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\DetalleConsolidado;
use DomainException;

/**
 * Detalle diario de un consolidado: foto congelada de la asistencia (deliberadamente redundante, para que corregir una
 * asistencia diaria despues del cierre no altere un consolidado ya liquidado). Solo se toca mientras el consolidado
 * esta GENERADO u OBSERVADO y su periodo de asistencia no esta cerrado.
 */
class DetalleConsolidadoService
{
    /** Por que el detalle de este consolidado ya no se modifica (null = se puede modificar). */
    public static function motivoDeBloqueo(ConsolidadoAsistencia $consolidado): ?string
    {
        if (in_array($consolidado->ConsolidadoAsistenciaEstado, ['CONFORME', 'CERRADO'], true)) {
            return 'El consolidado está '.($consolidado->ConsolidadoAsistenciaEstado === 'CERRADO' ? 'cerrado' : 'conforme').' y su detalle ya no se puede modificar.';
        }
        if ($consolidado->periodo?->PeriodoAsistenciaEstado === 'CERRADO') {
            return 'El período de asistencia está cerrado: el detalle del consolidado ya no se puede modificar.';
        }

        return null;
    }

    public function eliminar(DetalleConsolidado $detalle): void
    {
        if ($motivo = self::motivoDeBloqueo($detalle->consolidado)) {
            throw new DomainException($motivo);
        }
        $detalle->delete();
    }
}
