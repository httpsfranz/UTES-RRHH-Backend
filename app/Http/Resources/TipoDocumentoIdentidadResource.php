<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Personal\TipoDocumentoIdentidad
 */
class TipoDocumentoIdentidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->TipoDocumentoIdentidadId,
            'codigo'       => $this->TipoDocumentoIdentidadCodigo,
            'nombre'       => $this->TipoDocumentoIdentidadNombre,
            'abreviatura'  => $this->TipoDocumentoIdentidadAbreviatura,
            'longitud'     => $this->TipoDocumentoIdentidadLongitud,
            'activo'       => (bool) $this->TipoDocumentoIdentidadEstado,
        ];
    }
}
