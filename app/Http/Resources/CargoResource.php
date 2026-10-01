<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CargoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CargoId,
            'grupo_ocupacional_id' => $this->GrupoOcupacionalId,
            'codigo' => $this->CargoCodigo,
            'nombre' => $this->CargoNombre,
            'descripcion' => $this->CargoDescripcion,
            'es_jefatura' => (bool) $this->CargoEsJefatura,
            'activo' => (bool) $this->CargoEstado,
            'grupo_ocupacional' => $this->whenLoaded('grupoOcupacional', fn () => [
                'id' => $this->grupoOcupacional->GrupoOcupacionalId,
                'nombre' => $this->grupoOcupacional->GrupoOcupacionalNombre,
            ]),
        ];
    }
}
