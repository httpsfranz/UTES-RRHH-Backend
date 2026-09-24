<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Personal\GrupoOcupacional
 */
class GrupoOcupacionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->GrupoOcupacionalId,
            'codigo'      => $this->GrupoOcupacionalCodigo,
            'nombre'      => $this->GrupoOcupacionalNombre,
            'descripcion' => $this->GrupoOcupacionalDescripcion,
            'activo'      => (bool) $this->GrupoOcupacionalEstado,
        ];
    }
}
