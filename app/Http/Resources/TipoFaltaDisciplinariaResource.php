<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Disciplina\TipoFaltaDisciplinaria
 */
class TipoFaltaDisciplinariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->TipoFaltaDisciplinariaId,
            'codigo'      => $this->TipoFaltaDisciplinariaCodigo,
            'nombre'      => $this->TipoFaltaDisciplinariaNombre,
            'gravedad'    => $this->TipoFaltaDisciplinariaGravedad,
            'base_legal'  => $this->TipoFaltaDisciplinariaBaseLegal,
            'descripcion' => $this->TipoFaltaDisciplinariaDescripcion,
            'activo'      => (bool) $this->TipoFaltaDisciplinariaEstado,
        ];
    }
}
