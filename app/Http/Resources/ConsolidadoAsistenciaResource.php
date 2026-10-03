<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsolidadoAsistenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ConsolidadoAsistenciaId,
            'periodo_asistencia_id' => $this->PeriodoAsistenciaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'dias_trabajados' => $this->ConsolidadoAsistenciaDiasTrabajados,
            'dias_falta' => $this->ConsolidadoAsistenciaDiasFalta,
            'dias_falta_justificada' => $this->ConsolidadoAsistenciaDiasFaltaJustificada,
            'minutos_tardanza' => $this->ConsolidadoAsistenciaMinutosTardanza,
            'minutos_extra' => $this->ConsolidadoAsistenciaMinutosExtra,
            'fecha_generacion' => optional($this->ConsolidadoAsistenciaFechaGeneracion)->format('Y-m-d H:i:s'),
            'estado' => $this->ConsolidadoAsistenciaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ConsolidadoAsistenciaEstado, ['ANULADO', 'ANULADA'], true),
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
                'id' => $this->periodo->PeriodoAsistenciaId,
                'anio' => (int) $this->periodo->PeriodoAsistenciaAnio,
                'mes' => (int) $this->periodo->PeriodoAsistenciaMes,
                'estado' => $this->periodo->PeriodoAsistenciaEstado,
            ] : null),
        ];
    }
}
