<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Solicitudes\TipoPapeleta
 */
class TipoPapeletaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->TipoPapeletaId,
            'codigo'             => $this->TipoPapeletaCodigo,
            'nombre'             => $this->TipoPapeletaNombre,
            'descripcion'        => $this->TipoPapeletaDescripcion,
            'es_descontable'     => (bool) $this->TipoPapeletaEsDescontable,
            'requiere_sustento'  => (bool) $this->TipoPapeletaRequiereSustento,
            'afecta_jornada'     => (bool) $this->TipoPapeletaAfectaJornada,
            'es_compensable'     => (bool) $this->TipoPapeletaEsCompensable,
            'activo'             => (bool) $this->TipoPapeletaEstado,
        ];
    }
}
