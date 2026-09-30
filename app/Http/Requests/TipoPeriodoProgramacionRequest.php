<?php

namespace App\Http\Requests;

use App\Models\Programacion\TipoPeriodoProgramacion;

class TipoPeriodoProgramacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoPeriodoProgramacionCodigo' => $this->codigoUnico(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionCodigo', 30),
            'TipoPeriodoProgramacionNombre' => $this->nombreUnico(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionNombre', 100),
            'TipoPeriodoProgramacionDescripcion' => $this->texto(250),
            'TipoPeriodoProgramacionDias' => ['nullable', 'integer', 'between:1,366'],
            'TipoPeriodoProgramacionEstado' => $this->booleano(),
        ];
    }
}
