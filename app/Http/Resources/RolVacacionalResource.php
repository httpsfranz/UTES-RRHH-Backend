<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RolVacacionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->RolVacacionalId,
            'periodo_vacacional_id' => $this->PeriodoVacacionalId,
            'fecha_programada' => optional($this->RolVacacionalFechaProgramada)->format('Y-m-d'),
            'fecha_fin_programada' => optional($this->RolVacacionalFechaFinProgramada)->format('Y-m-d'),
            'dias' => $this->RolVacacionalDias,
            'estado' => $this->RolVacacionalEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->RolVacacionalEstado, ['ANULADO', 'ANULADA'], true),
            'goces_registrados' => isset($this->goces_count) ? (int) $this->goces_count : null,
            'trabajador' => $this->whenLoaded('periodoVacacional', fn () => $this->periodoVacacional?->vinculoLaboral?->relationLoaded('trabajador') && $this->periodoVacacional?->vinculoLaboral->trabajador ? [
                'id' => $this->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('periodoVacacional', fn () => $this->periodoVacacional?->vinculoLaboral ? [
                'id' => $this->periodoVacacional?->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->periodoVacacional?->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->periodoVacacional?->vinculoLaboral->EessId,
            ] : null),
            'periodo_vacacional' => $this->whenLoaded('periodoVacacional', fn () => $this->periodoVacacional ? [
                'id' => $this->periodoVacacional->PeriodoVacacionalId,
                'anio' => (int) $this->periodoVacacional->PeriodoVacacionalAnio,
                'dias_ganados' => $this->periodoVacacional->PeriodoVacacionalDiasGanados,
                'dias_disponibles' => $this->periodoVacacional->PeriodoVacacionalDiasDisponibles,
                'estado' => $this->periodoVacacional->PeriodoVacacionalEstado,
            ] : null),
        ];
    }
}
