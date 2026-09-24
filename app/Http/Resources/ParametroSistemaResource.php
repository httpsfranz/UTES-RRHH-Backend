<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Configuracion\ParametroSistema
 */
class ParametroSistemaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->ParametroSistemaId,
            'codigo'      => $this->ParametroSistemaCodigo,
            'valor'       => $this->ParametroSistemaValor,
            'descripcion' => $this->ParametroSistemaDescripcion,
            'activo'      => (bool) $this->ParametroSistemaEstado,
        ];
    }
}
