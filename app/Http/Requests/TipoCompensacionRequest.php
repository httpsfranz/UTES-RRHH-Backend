<?php

namespace App\Http\Requests;

use App\Models\Compensaciones\TipoCompensacion;

class TipoCompensacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoCompensacionCodigo' => $this->codigoUnico(TipoCompensacion::class, 'TipoCompensacionCodigo', 30),
            'TipoCompensacionNombre' => $this->nombreUnico(TipoCompensacion::class, 'TipoCompensacionNombre', 150),
            'TipoCompensacionDescripcion' => $this->texto(300),
            'TipoCompensacionEstado' => $this->booleano(),
        ];
    }
}
