<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetalleConsolidadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->DetalleConsolidadoId,
            'consolidado_asistencia_id' => $this->ConsolidadoAsistenciaId,
            'asistencia_diaria_id' => $this->AsistenciaDiariaId,
            'fecha' => optional($this->DetalleConsolidadoFecha)->format('Y-m-d'),
            'estado' => $this->DetalleConsolidadoEstado,
            'minutos_tardanza' => $this->DetalleConsolidadoMinutosTardanza,
            'minutos_extra' => $this->DetalleConsolidadoMinutosExtra,
            'es_justificada' => (bool) $this->DetalleConsolidadoEsJustificada,
            'trabajador' => $this->whenLoaded('consolidado', fn () => $this->consolidado?->vinculoLaboral?->relationLoaded('trabajador') && $this->consolidado?->vinculoLaboral->trabajador ? [
                'id' => $this->consolidado?->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->consolidado?->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->consolidado?->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('consolidado', fn () => $this->consolidado?->vinculoLaboral ? [
                'id' => $this->consolidado?->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->consolidado?->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->consolidado?->vinculoLaboral->EessId,
            ] : null),
            'consolidado' => $this->whenLoaded('consolidado', fn () => $this->consolidado ? [
                'id' => $this->consolidado->ConsolidadoAsistenciaId,
                'periodo_asistencia_id' => $this->consolidado->PeriodoAsistenciaId,
                'estado' => $this->consolidado->ConsolidadoAsistenciaEstado,
            ] : null),
        ];
    }
}
