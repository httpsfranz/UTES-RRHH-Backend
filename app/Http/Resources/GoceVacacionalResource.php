<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoceVacacionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->GoceVacacionalId,
            'rol_vacacional_id' => $this->RolVacacionalId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha_inicio' => optional($this->GoceVacacionalFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->GoceVacacionalFechaFin)->format('Y-m-d'),
            'dias' => $this->GoceVacacionalDias,
            'estado' => $this->GoceVacacionalEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->GoceVacacionalEstado, ['ANULADO', 'ANULADA'], true),
            'trabajador' => $this->whenLoaded('rolVacacional', fn () => $this->rolVacacional?->periodoVacacional?->vinculoLaboral?->relationLoaded('trabajador') && $this->rolVacacional?->periodoVacacional?->vinculoLaboral->trabajador ? [
                'id' => $this->rolVacacional?->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->rolVacacional?->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->rolVacacional?->periodoVacacional?->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('rolVacacional', fn () => $this->rolVacacional?->periodoVacacional?->vinculoLaboral ? [
                'id' => $this->rolVacacional?->periodoVacacional?->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->rolVacacional?->periodoVacacional?->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->rolVacacional?->periodoVacacional?->vinculoLaboral->EessId,
            ] : null),
            'rol_vacacional' => $this->whenLoaded('rolVacacional', fn () => $this->rolVacacional ? [
                'id' => $this->rolVacacional->RolVacacionalId,
                'fecha_programada' => optional($this->rolVacacional->RolVacacionalFechaProgramada)->format('Y-m-d'),
                'fecha_fin_programada' => optional($this->rolVacacional->RolVacacionalFechaFinProgramada)->format('Y-m-d'),
                'dias' => $this->rolVacacional->RolVacacionalDias,
                'estado' => $this->rolVacacional->RolVacacionalEstado,
                'periodo_vacacional_id' => $this->rolVacacional->PeriodoVacacionalId,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
