<?php

namespace App\Http\Requests;

use App\Models\Programacion\TipoCambioTurno;

class TipoCambioTurnoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoCambioTurnoCodigo' => $this->codigoUnico(TipoCambioTurno::class, 'TipoCambioTurnoCodigo', 30),
            'TipoCambioTurnoNombre' => $this->nombreUnico(TipoCambioTurno::class, 'TipoCambioTurnoNombre', 100),
            'TipoCambioTurnoDescripcion' => $this->texto(250),
            'TipoCambioTurnoRequiereReemplazante' => $this->booleano(),
            'TipoCambioTurnoEstado' => $this->booleano(),
        ];
    }
}
