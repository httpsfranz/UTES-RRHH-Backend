<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColegiaturaTipoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->ColegiaturaTipoId,
            'codigo'      => $this->ColegiaturaTipoCodigo,
            'nombre'      => $this->ColegiaturaTipoNombre,
            'entidad'     => $this->ColegiaturaTipoEntidad,
            'descripcion' => $this->ColegiaturaTipoDescripcion,
            'activo'      => (bool) $this->ColegiaturaTipoEstado,
            'profesion'   => $this->whenLoaded('profesion', fn () => [
                'id'     => $this->profesion->ProfesionId,
                'nombre' => $this->profesion->ProfesionNombre,
            ]),
        ];
    }
}
