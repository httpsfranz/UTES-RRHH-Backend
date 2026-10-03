<?php

namespace App\Support;

use App\Models\Consolidacion\PeriodoAsistencia;

/**
 * Consultas sobre los periodos de asistencia. Un periodo CERRADO ya se consolido y liquido: lo que cae en el es
 * inmutable, tanto desde un Form Request como desde un Service.
 */
class PeriodosDeAsistencia
{
    /** Hay un periodo CERRADO que cubre alguna fecha del rango [$desde, $hasta] (por omision, solo $desde). */
    public static function cerradoEn(?string $desde, ?string $hasta = null): bool
    {
        if (! $desde) {
            return false;
        }
        $hasta ??= $desde;

        return PeriodoAsistencia::query()
            ->where('PeriodoAsistenciaEstado', 'CERRADO')
            ->whereDate('PeriodoAsistenciaFechaInicio', '<=', $hasta)
            ->whereDate('PeriodoAsistenciaFechaFin', '>=', $desde)
            ->exists();
    }
}
