<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organizacion\Microred
 */
class MicroredResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->MicroredId,
            'codigo'      => $this->MicroredCodigo,
            'nombre'      => $this->MicroredNombre,
            'distrito'    => $this->MicroredDistrito,
            'ubigeo'      => $this->MicroredUbigeo,
            'direccion'   => $this->MicroredDireccion,
            'telefono'    => $this->MicroredTelefono,
            'descripcion' => $this->MicroredDescripcion,
            'activo'      => (bool) $this->MicroredEstado,
        ];
    }
}
