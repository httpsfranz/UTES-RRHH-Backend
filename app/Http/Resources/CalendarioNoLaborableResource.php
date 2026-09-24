<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarioNoLaborableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->CalendarioNoLaborableId,
            'microred_id'     => $this->MicroredId,
            'fecha'           => optional($this->CalendarioNoLaborableFecha)->format('Y-m-d'),
            'tipo'            => $this->CalendarioNoLaborableTipo,
            'descripcion'     => $this->CalendarioNoLaborableDescripcion,
            'compensable'     => (bool) $this->CalendarioNoLaborableCompensable,
            'norma_sustento'  => $this->CalendarioNoLaborableNormaSustento,
        ];
    }
}
