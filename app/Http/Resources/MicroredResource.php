<?php

namespace App\Http\Resources;

use App\Models\Organizacion\Microred;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Microred
 */
class MicroredResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->MicroredId,
            'codigo' => $this->MicroredCodigo,
            'nombre' => $this->MicroredNombre,
            'distrito' => $this->MicroredDistrito,
            'ubigeo' => $this->MicroredUbigeo,
            'direccion' => $this->MicroredDireccion,
            'telefono' => $this->MicroredTelefono,
            'descripcion' => $this->MicroredDescripcion,
            'activo' => (bool) $this->MicroredEstado,
            'establecimientos_total' => $this->when(array_key_exists('establecimientos_total', $this->getAttributes()), fn () => (int) $this->establecimientos_total),
        ];
    }
}
