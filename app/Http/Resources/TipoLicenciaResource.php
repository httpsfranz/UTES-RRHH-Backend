<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Solicitudes\TipoLicencia
 */
class TipoLicenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->TipoLicenciaId,
            'codigo'       => $this->TipoLicenciaCodigo,
            'nombre'       => $this->TipoLicenciaNombre,
            'descripcion'  => $this->TipoLicenciaDescripcion,
            'con_goce'     => (bool) $this->TipoLicenciaConGoce,
            'maximo_dias'  => $this->TipoLicenciaMaximoDias,
            'base_legal'   => $this->TipoLicenciaBaseLegal,
            'activo'       => (bool) $this->TipoLicenciaEstado,
        ];
    }
}
