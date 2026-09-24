<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Personal\RegimenLaboral
 */
class RegimenLaboralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->RegimenLaboralId,
            'codigo'      => $this->RegimenLaboralCodigo,
            'nombre'      => $this->RegimenLaboralNombre,
            'base_legal'  => $this->RegimenLaboralBaseLegal,
            'descripcion' => $this->RegimenLaboralDescripcion,
            'activo'      => (bool) $this->RegimenLaboralEstado,
        ];
    }
}
