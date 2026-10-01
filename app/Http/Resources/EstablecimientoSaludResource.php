<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablecimientoSaludResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->EessId,
            'microred_id' => $this->MicroredId,
            'tipo_establecimiento_id' => $this->TipoEstablecimientoId,
            'codigo' => $this->EessCodigo,
            'renipres' => $this->EessCodigoRenipres,
            'nombre' => $this->EessNombre,
            'categoria' => $this->EessCategoria,
            'ubigeo' => $this->EessUbigeo,
            'direccion' => $this->EessDireccion,
            'telefono' => $this->EessTelefono,
            'descripcion' => $this->EessDescripcion,
            'activo' => (bool) $this->EessEstado,
            'microred' => $this->whenLoaded('microred', fn () => [
                'id' => $this->microred->MicroredId,
                'nombre' => $this->microred->MicroredNombre,
            ]),
            'tipo' => $this->whenLoaded('tipoEstablecimiento', fn () => [
                'id' => $this->tipoEstablecimiento->TipoEstablecimientoId,
                'nombre' => $this->tipoEstablecimiento->TipoEstablecimientoNombre,
            ]),
        ];
    }
}
