<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfesionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->ProfesionId,
            'codigo'                => $this->ProfesionCodigo,
            'nombre'                => $this->ProfesionNombre,
            'descripcion'           => $this->ProfesionDescripcion,
            'requiere_colegiatura'  => (bool) $this->ProfesionRequiereColegiatura,
            'activo'                => (bool) $this->ProfesionEstado,
        ];
    }
}
