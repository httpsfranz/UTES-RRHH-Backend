<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiquidacionDescuentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->LiquidacionDescuentoId,
            'consolidado_asistencia_id' => $this->ConsolidadoAsistenciaId,
            'fecha_generacion' => optional($this->LiquidacionDescuentoFechaGeneracion)->format('Y-m-d H:i:s'),
            'importe_total' => $this->LiquidacionDescuentoImporteTotal,
            'estado' => $this->LiquidacionDescuentoEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->LiquidacionDescuentoEstado, ['ANULADO', 'ANULADA'], true),
            'lineas' => isset($this->detalles_count) ? (int) $this->detalles_count : null,
            'detalles' => DetalleLiquidacionResource::collection($this->whenLoaded('detalles')),
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
                'anio' => $this->consolidado->relationLoaded('periodo') ? $this->consolidado->periodo?->PeriodoAsistenciaAnio : null,
                'mes' => $this->consolidado->relationLoaded('periodo') ? $this->consolidado->periodo?->PeriodoAsistenciaMes : null,
                'estado' => $this->consolidado->ConsolidadoAsistenciaEstado,
                'dias_falta' => $this->consolidado->ConsolidadoAsistenciaDiasFalta,
                'minutos_tardanza' => $this->consolidado->ConsolidadoAsistenciaMinutosTardanza,
            ] : null),
        ];
    }
}
