<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsignacionHorarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->AsignacionHorarioId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'horario_id' => $this->HorarioId,
            'fecha_inicio' => optional($this->AsignacionHorarioFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->AsignacionHorarioFechaFin)->format('Y-m-d'),
            'observacion' => $this->AsignacionHorarioObservacion,
            'fecha_registro' => optional($this->AsignacionHorarioFechaRegistro)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->AsignacionHorarioEstado,
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
            'horario' => $this->whenLoaded('horario', fn () => $this->horario ? [
                'id' => $this->horario->HorarioId,
                'codigo' => $this->horario->HorarioCodigo,
                'nombre' => $this->horario->HorarioNombre,
            ] : null),
        ];
    }
}
