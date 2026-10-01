<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotivoPapeletaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->MotivoPapeletaId,
            'tipo_papeleta_id' => $this->TipoPapeletaId,
            'codigo' => $this->MotivoPapeletaCodigo,
            'nombre' => $this->MotivoPapeletaNombre,
            'descripcion' => $this->MotivoPapeletaDescripcion,
            'activo' => (bool) $this->MotivoPapeletaEstado,
            'tipo_papeleta' => $this->whenLoaded('tipoPapeleta', fn () => [
                'id' => $this->tipoPapeleta->TipoPapeletaId,
                'codigo' => $this->tipoPapeleta->TipoPapeletaCodigo,
                'nombre' => $this->tipoPapeleta->TipoPapeletaNombre,
            ]),
        ];
    }
}
