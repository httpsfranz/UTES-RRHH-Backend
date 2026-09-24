<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Compensaciones\TipoCompensacion
 */
class TipoCompensacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoCompensacionId,
            'codigo'      => $this->TipoCompensacionCodigo,
            'nombre'      => $this->TipoCompensacionNombre,
            'descripcion' => $this->TipoCompensacionDescripcion,
            'activo'      => (bool) $this->TipoCompensacionEstado,
        ];
    }
}
