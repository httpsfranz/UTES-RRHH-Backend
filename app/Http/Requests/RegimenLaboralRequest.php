<?php

namespace App\Http\Requests;

use App\Models\Personal\RegimenLaboral;

class RegimenLaboralRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'RegimenLaboralCodigo' => $this->codigoUnico(RegimenLaboral::class, 'RegimenLaboralCodigo', 30),
            'RegimenLaboralNombre' => $this->nombreUnico(RegimenLaboral::class, 'RegimenLaboralNombre', 100),
            'RegimenLaboralDescripcion' => $this->texto(250),
            'RegimenLaboralBaseLegal' => $this->texto(150),
            'RegimenLaboralEstado' => $this->booleano(),
        ];
    }
}
