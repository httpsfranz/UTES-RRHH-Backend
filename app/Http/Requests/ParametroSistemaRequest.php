<?php

namespace App\Http\Requests;

use App\Models\Configuracion\ParametroSistema;

class ParametroSistemaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        // Esta tabla NO tiene columna "Nombre": es clave/valor, no un catalogo con nombre.
        return [
            'ParametroSistemaCodigo' => $this->codigoUnico(ParametroSistema::class, 'ParametroSistemaCodigo', 100),
            'ParametroSistemaValor' => $this->texto(500),
            'ParametroSistemaDescripcion' => $this->texto(300),
            'ParametroSistemaEstado' => $this->booleano(),
        ];
    }
}
