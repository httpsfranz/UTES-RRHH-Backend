<?php

namespace App\Http\Requests;

use App\Models\Compensaciones\ConceptoDescuento;

class ConceptoDescuentoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'ConceptoDescuentoCodigo' => $this->codigoUnico(ConceptoDescuento::class, 'ConceptoDescuentoCodigo', 50),
            'ConceptoDescuentoNombre' => $this->nombreUnico(ConceptoDescuento::class, 'ConceptoDescuentoNombre', 150),
            'ConceptoDescuentoDescripcion' => $this->texto(300),
            'ConceptoDescuentoEstado' => $this->booleano(),
        ];
    }
}
