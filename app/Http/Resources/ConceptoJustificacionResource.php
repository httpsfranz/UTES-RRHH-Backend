<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Asistencia\ConceptoJustificacion
 */
class ConceptoJustificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->ConceptoJustificacionId,
            'codigo'             => $this->ConceptoJustificacionCodigo,
            'nombre'             => $this->ConceptoJustificacionNombre,
            'descripcion'        => $this->ConceptoJustificacionDescripcion,
            'requiere_documento' => (bool) $this->ConceptoJustificacionRequiereDocumento,
            'es_remunerado'      => (bool) $this->ConceptoJustificacionEsRemunerado,
            'activo'             => (bool) $this->ConceptoJustificacionEstado,
        ];
    }
}
