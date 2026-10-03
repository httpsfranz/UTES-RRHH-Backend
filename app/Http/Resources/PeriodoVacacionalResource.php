<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodoVacacionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->PeriodoVacacionalId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'anio' => $this->PeriodoVacacionalAnio,
            'fecha_inicio' => optional($this->PeriodoVacacionalFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->PeriodoVacacionalFechaFin)->format('Y-m-d'),
            'dias_ganados' => $this->PeriodoVacacionalDiasGanados,
            'dias_disponibles' => $this->PeriodoVacacionalDiasDisponibles,
            'estado' => $this->PeriodoVacacionalEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->PeriodoVacacionalEstado, ['ANULADO', 'ANULADA'], true),
            'trabajador' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral?->relationLoaded('trabajador') && $this->vinculoLaboral->trabajador ? [
                'id' => $this->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral ? [
                'id' => $this->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->vinculoLaboral->EessId,
            ] : null),
        ];
    }
}
