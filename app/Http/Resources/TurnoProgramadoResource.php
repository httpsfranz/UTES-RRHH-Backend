<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TurnoProgramadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TurnoProgramadoId,
            'programacion_trabajador_id' => $this->ProgramacionTrabajadorId,
            'turno_id' => $this->TurnoId,
            'fecha' => optional($this->TurnoProgramadoFecha)->format('Y-m-d'),
            'hora_entrada' => $this->TurnoProgramadoHoraEntrada === null ? null : substr((string) $this->TurnoProgramadoHoraEntrada, 0, 5),
            'hora_salida' => $this->TurnoProgramadoHoraSalida === null ? null : substr((string) $this->TurnoProgramadoHoraSalida, 0, 5),
            'es_guardia' => (bool) $this->TurnoProgramadoEsGuardia,
            'observacion' => $this->TurnoProgramadoObservacion,
            'estado' => $this->TurnoProgramadoEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->TurnoProgramadoEstado, ['ANULADO', 'ANULADA'], true),
            'trabajador' => $this->whenLoaded('programacionTrabajador', fn () => $this->programacionTrabajador?->vinculoLaboral?->relationLoaded('trabajador') && $this->programacionTrabajador?->vinculoLaboral->trabajador ? [
                'id' => $this->programacionTrabajador?->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->programacionTrabajador?->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->programacionTrabajador?->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('programacionTrabajador', fn () => $this->programacionTrabajador?->vinculoLaboral ? [
                'id' => $this->programacionTrabajador?->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->programacionTrabajador?->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->programacionTrabajador?->vinculoLaboral->EessId,
            ] : null),
            'turno' => $this->whenLoaded('turno', fn () => $this->turno ? [
                'id' => $this->turno->TurnoId,
                'codigo' => $this->turno->TurnoCodigo,
                'nombre' => $this->turno->TurnoNombre,
                'hora_entrada' => $this->turno->TurnoHoraEntrada === null ? null : substr((string) $this->turno->TurnoHoraEntrada, 0, 5),
                'hora_salida' => $this->turno->TurnoHoraSalida === null ? null : substr((string) $this->turno->TurnoHoraSalida, 0, 5),
                'es_guardia' => (bool) $this->turno->TurnoEsGuardia,
            ] : null),
            'programacion_trabajador' => $this->whenLoaded('programacionTrabajador', fn () => $this->programacionTrabajador ? [
                'id' => $this->programacionTrabajador->ProgramacionTrabajadorId,
                'programacion_periodo_id' => $this->programacionTrabajador->ProgramacionPeriodoId,
                'estado' => $this->programacionTrabajador->ProgramacionTrabajadorEstado,
            ] : null),
            'duracion_minutos' => $this->relationLoaded('turno') ? $this->minutosEfectivos() : null,
        ];
    }
}
