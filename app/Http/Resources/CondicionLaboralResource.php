<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Personal\CondicionLaboral
 */
class CondicionLaboralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->CondicionLaboralId,
            'codigo'            => $this->CondicionLaboralCodigo,
            'nombre'            => $this->CondicionLaboralNombre,
            'descripcion'       => $this->CondicionLaboralDescripcion,
            'es_permanente'     => (bool) $this->CondicionLaboralEsPermanente,
            'requiere_airhsp'   => (bool) $this->CondicionLaboralRequiereAirhsp,
            'activo'            => (bool) $this->CondicionLaboralEstado,
        ];
    }
}
