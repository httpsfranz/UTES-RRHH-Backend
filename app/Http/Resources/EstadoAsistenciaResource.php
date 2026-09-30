<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstadoAsistenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->EstadoAsistenciaId,
            'codigo' => $this->EstadoAsistenciaCodigo,
            'nombre' => $this->EstadoAsistenciaNombre,
            'descripcion' => $this->EstadoAsistenciaDescripcion,
            'es_falta' => (bool) $this->EstadoAsistenciaEsFalta,
            'es_descontable' => (bool) $this->EstadoAsistenciaEsDescontable,
            'es_laborable' => (bool) $this->EstadoAsistenciaEsLaborable,
            'activo' => (bool) $this->EstadoAsistenciaEstado,
        ];
    }
}
