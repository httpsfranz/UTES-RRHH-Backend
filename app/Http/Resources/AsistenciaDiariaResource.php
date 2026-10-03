<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaDiariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->AsistenciaDiariaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'turno_programado_id' => $this->TurnoProgramadoId,
            'estado_asistencia_id' => $this->EstadoAsistenciaId,
            'justificacion_falta_id' => $this->JustificacionFaltaId,
            'fecha' => optional($this->AsistenciaDiariaFecha)->format('Y-m-d'),
            'hora_entrada' => optional($this->AsistenciaDiariaHoraEntrada)->format('Y-m-d H:i:s'),
            'hora_salida' => optional($this->AsistenciaDiariaHoraSalida)->format('Y-m-d H:i:s'),
            'minutos_tardanza' => $this->AsistenciaDiariaMinutosTardanza,
            'minutos_falta' => $this->AsistenciaDiariaMinutosFalta,
            'minutos_extra' => $this->AsistenciaDiariaMinutosExtra,
            'minutos_trabajados' => $this->AsistenciaDiariaMinutosTrabajados,
            'observacion' => $this->AsistenciaDiariaObservacion,
            'fecha_proceso' => optional($this->AsistenciaDiariaFechaProceso)->format('Y-m-d H:i:s'),
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
            'estado' => $this->whenLoaded('estado', fn () => $this->estado ? [
                'id' => $this->estado->EstadoAsistenciaId,
                'codigo' => $this->estado->EstadoAsistenciaCodigo,
                'nombre' => $this->estado->EstadoAsistenciaNombre,
                'es_falta' => (bool) $this->estado->EstadoAsistenciaEsFalta,
                'es_descontable' => (bool) $this->estado->EstadoAsistenciaEsDescontable,
            ] : null),
        ];
    }
}
