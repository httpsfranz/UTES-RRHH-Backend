<?php

namespace App\Http\Requests;

use App\Models\Biometria\MetodoMarcacion;

class MetodoMarcacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'MetodoMarcacionCodigo' => $this->codigoUnico(MetodoMarcacion::class, 'MetodoMarcacionCodigo', 50),
            'MetodoMarcacionNombre' => $this->nombreUnico(MetodoMarcacion::class, 'MetodoMarcacionNombre', 100),
            'MetodoMarcacionDescripcion' => $this->texto(250),
            'MetodoMarcacionEstado' => $this->booleano(),
        ];
    }
}
