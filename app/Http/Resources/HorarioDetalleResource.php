<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HorarioDetalleResource extends JsonResource
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->HorarioDetalleId,
            'horario_id' => $this->HorarioId,
            'turno_id' => $this->TurnoId,
            'dia' => $this->HorarioDetalleDia,
            'dia_nombre' => self::DIAS[$this->HorarioDetalleDia] ?? null,
            'orden' => $this->HorarioDetalleOrden,
            'es_descanso' => (bool) $this->HorarioDetalleEsDescanso,
            'horario' => $this->whenLoaded('horario', fn () => [
                'id' => $this->horario->HorarioId,
                'codigo' => $this->horario->HorarioCodigo,
                'nombre' => $this->horario->HorarioNombre,
            ]),
            'turno' => $this->whenLoaded('turno', fn () => [
                'id' => $this->turno->TurnoId,
                'codigo' => $this->turno->TurnoCodigo,
                'nombre' => $this->turno->TurnoNombre,
                'hora_entrada' => substr((string) $this->turno->TurnoHoraEntrada, 0, 5),
                'hora_salida' => substr((string) $this->turno->TurnoHoraSalida, 0, 5),
            ]),
        ];
    }
}
