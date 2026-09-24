<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organizacion\TipoResponsabilidad
 */
class TipoResponsabilidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoResponsabilidadId,
            'codigo'      => $this->TipoResponsabilidadCodigo,
            'nombre'      => $this->TipoResponsabilidadNombre,
            'descripcion' => $this->TipoResponsabilidadDescripcion,
            'activo'      => (bool) $this->TipoResponsabilidadEstado,
        ];
    }
}
