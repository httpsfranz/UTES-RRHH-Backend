<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TipoJornada;

class TipoJornadaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoJornadaCodigo' => $this->codigoUnico(TipoJornada::class, 'TipoJornadaCodigo', 30),
            'TipoJornadaNombre' => $this->nombreUnico(TipoJornada::class, 'TipoJornadaNombre', 100),
            'TipoJornadaDescripcion' => $this->texto(250),
            'TipoJornadaEstado' => $this->booleano(),
        ];
    }
}
