<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramacionTrabajadorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ProgramacionTrabajadorId,
            'programacion_periodo_id' => $this->ProgramacionPeriodoId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'horas_programadas' => $this->ProgramacionTrabajadorHorasProgramadas,
            'observacion' => $this->ProgramacionTrabajadorObservacion,
            'estado' => $this->ProgramacionTrabajadorEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ProgramacionTrabajadorEstado, ['ANULADO', 'ANULADA'], true),
            'turnos_programados' => isset($this->turnos_count) ? (int) $this->turnos_count : null,
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
            'periodo' => $this->whenLoaded('periodo', fn () => $this->periodo ? [
                'id' => $this->periodo->ProgramacionPeriodoId,
                'codigo' => $this->periodo->ProgramacionPeriodoCodigo,
                'estado' => $this->periodo->ProgramacionPeriodoEstado,
                'eess_id' => $this->periodo->EessId,
                'fecha_inicio' => optional($this->periodo->ProgramacionPeriodoFechaInicio)->format('Y-m-d'),
                'fecha_fin' => optional($this->periodo->ProgramacionPeriodoFechaFin)->format('Y-m-d'),
            ] : null),
        ];
    }
}
