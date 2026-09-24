<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Biometria\MetodoMarcacion
 */
class MetodoMarcacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->MetodoMarcacionId,
            'codigo'      => $this->MetodoMarcacionCodigo,
            'nombre'      => $this->MetodoMarcacionNombre,
            'descripcion' => $this->MetodoMarcacionDescripcion,
            'activo'      => (bool) $this->MetodoMarcacionEstado,
        ];
    }
}
