<?php

namespace App\Http\Requests;

use App\Models\Personal\TipoDocumentoIdentidad;

class TipoDocumentoIdentidadRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoDocumentoIdentidadCodigo' => $this->codigoUnico(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadCodigo', 20),
            'TipoDocumentoIdentidadNombre' => $this->nombreUnico(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadNombre', 100),
            'TipoDocumentoIdentidadAbreviatura' => $this->texto(20),
            'TipoDocumentoIdentidadLongitud' => ['nullable', 'integer', 'between:1,20'],
            'TipoDocumentoIdentidadEstado' => $this->booleano(),
        ];
    }
}
