<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Configuracion\TipoJornada
 */
class TipoJornadaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoJornadaId,
            'codigo'      => $this->TipoJornadaCodigo,
            'nombre'      => $this->TipoJornadaNombre,
            'descripcion' => $this->TipoJornadaDescripcion,
            'activo'      => (bool) $this->TipoJornadaEstado,
        ];
    }
}
