<?php

namespace App\Http\Requests;

use App\Models\Asistencia\ConceptoJustificacion;

class ConceptoJustificacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'ConceptoJustificacionCodigo' => $this->codigoUnico(ConceptoJustificacion::class, 'ConceptoJustificacionCodigo', 30),
            'ConceptoJustificacionNombre' => $this->nombreUnico(ConceptoJustificacion::class, 'ConceptoJustificacionNombre', 150),
            'ConceptoJustificacionDescripcion' => $this->texto(300),
            'ConceptoJustificacionRequiereDocumento' => $this->booleano(),
            'ConceptoJustificacionEsRemunerado' => $this->booleano(),
            'ConceptoJustificacionEstado' => $this->booleano(),
        ];
    }
}
