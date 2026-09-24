<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConceptoDescuentoResource extends JsonResource
{
    
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ConceptoDescuentoId,
            'codigo' => $this->ConceptoDescuentoCodigo,
            'nombre' => $this->ConceptoDescuentoNombre,
            'descripcion' => $this->ConceptoDescuentoDescripcion,
            'activo' => (bool) $this->ConceptoDescuentoEstado,
        ];
    }
}
