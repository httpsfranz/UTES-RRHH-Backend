<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Programacion\TipoPeriodoProgramacion
 */
class TipoPeriodoProgramacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoPeriodoProgramacionId,
            'codigo'      => $this->TipoPeriodoProgramacionCodigo,
            'nombre'      => $this->TipoPeriodoProgramacionNombre,
            'dias'        => $this->TipoPeriodoProgramacionDias,
            'descripcion' => $this->TipoPeriodoProgramacionDescripcion,
            'activo'      => (bool) $this->TipoPeriodoProgramacionEstado,
        ];
    }
}
