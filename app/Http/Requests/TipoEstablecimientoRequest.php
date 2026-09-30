<?php

namespace App\Http\Requests;

use App\Models\Organizacion\TipoEstablecimiento;

class TipoEstablecimientoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoEstablecimientoCodigo' => $this->codigoUnico(TipoEstablecimiento::class, 'TipoEstablecimientoCodigo', 30),
            'TipoEstablecimientoNombre' => $this->nombreUnico(TipoEstablecimiento::class, 'TipoEstablecimientoNombre', 100),
            'TipoEstablecimientoDescripcion' => $this->texto(250),
            'TipoEstablecimientoEstado' => $this->booleano(),
        ];
    }
}
