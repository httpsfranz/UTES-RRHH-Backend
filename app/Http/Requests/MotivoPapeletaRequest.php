<?php

namespace App\Http\Requests;

use App\Models\Solicitudes\MotivoPapeleta;
use App\Models\Solicitudes\TipoPapeleta;

class MotivoPapeletaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoPapeletaId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoPapeleta::class, 'TipoPapeletaEstado', 'TipoPapeletaId'),
            ],
            'MotivoPapeletaCodigo' => $this->codigoUnico(MotivoPapeleta::class, 'MotivoPapeletaCodigo', 30),
            'MotivoPapeletaNombre' => $this->nombreUnico(MotivoPapeleta::class, 'MotivoPapeletaNombre', 150),
            'MotivoPapeletaDescripcion' => $this->texto(300),
            'MotivoPapeletaEstado' => $this->booleano(),
        ];
    }
}
