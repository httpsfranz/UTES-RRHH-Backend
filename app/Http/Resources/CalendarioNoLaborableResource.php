<?php

namespace App\Http\Resources;

use App\Services\CalendarioNoLaborableService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarioNoLaborableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CalendarioNoLaborableId,
            'microred_id' => $this->MicroredId,
            'fecha' => optional($this->CalendarioNoLaborableFecha)->format('Y-m-d'),
            'tipo' => $this->CalendarioNoLaborableTipo,
            'descripcion' => $this->CalendarioNoLaborableDescripcion,
            'compensable' => (bool) $this->CalendarioNoLaborableCompensable,
            'norma_sustento' => $this->CalendarioNoLaborableNormaSustento,
            'activo' => (bool) $this->CalendarioNoLaborableEstado,
            // Periodos de asistencia que abarca la fecha (un cambio recalcula su asistencia; cerrados no admiten cambios).
            'periodos_afectados' => $this->CalendarioNoLaborableFecha ? CalendarioNoLaborableService::periodosAfectados($this->CalendarioNoLaborableFecha->toDateString()) : [],
            'microred' => $this->whenLoaded('microred', fn () => $this->microred ? [
                'id' => $this->microred->MicroredId,
                'nombre' => $this->microred->MicroredNombre,
            ] : null),
        ];
    }
}
