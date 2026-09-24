<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organizacion\TipoEstablecimiento
 */
class TipoEstablecimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoEstablecimientoId,
            'codigo'      => $this->TipoEstablecimientoCodigo,
            'nombre'      => $this->TipoEstablecimientoNombre,
            'descripcion' => $this->TipoEstablecimientoDescripcion,
            'activo'      => (bool) $this->TipoEstablecimientoEstado,
        ];
    }
}
