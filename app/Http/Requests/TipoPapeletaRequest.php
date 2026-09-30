<?php

namespace App\Http\Requests;

use App\Models\Solicitudes\TipoPapeleta;

class TipoPapeletaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoPapeletaCodigo' => $this->codigoUnico(TipoPapeleta::class, 'TipoPapeletaCodigo', 30),
            'TipoPapeletaNombre' => $this->nombreUnico(TipoPapeleta::class, 'TipoPapeletaNombre', 150),
            'TipoPapeletaDescripcion' => $this->texto(300),
            'TipoPapeletaEsDescontable' => $this->booleano(),
            'TipoPapeletaRequiereSustento' => $this->booleano(),
            'TipoPapeletaAfectaJornada' => $this->booleano(),
            'TipoPapeletaEsCompensable' => $this->booleano(),
            'TipoPapeletaEstado' => $this->booleano(),
        ];
    }
}
