<?php

namespace App\Http\Requests;

use App\Models\Personal\CondicionLaboral;

class CondicionLaboralRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'CondicionLaboralCodigo' => $this->codigoUnico(CondicionLaboral::class, 'CondicionLaboralCodigo', 30),
            'CondicionLaboralNombre' => $this->nombreUnico(CondicionLaboral::class, 'CondicionLaboralNombre', 100),
            'CondicionLaboralDescripcion' => $this->texto(250),
            'CondicionLaboralEsPermanente' => $this->booleano(),
            'CondicionLaboralRequiereAirhsp' => $this->booleano(),
            'CondicionLaboralEstado' => $this->booleano(),
        ];
    }
}
