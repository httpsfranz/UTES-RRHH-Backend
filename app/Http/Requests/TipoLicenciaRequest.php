<?php

namespace App\Http\Requests;

use App\Models\Solicitudes\TipoLicencia;

class TipoLicenciaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoLicenciaCodigo' => $this->codigoUnico(TipoLicencia::class, 'TipoLicenciaCodigo', 30),
            'TipoLicenciaNombre' => $this->nombreUnico(TipoLicencia::class, 'TipoLicenciaNombre', 150),
            'TipoLicenciaDescripcion' => $this->texto(300),
            'TipoLicenciaConGoce' => $this->booleano(),
            'TipoLicenciaMaximoDias' => ['nullable', 'integer', 'between:1,3650'],
            'TipoLicenciaBaseLegal' => $this->texto(200),
            'TipoLicenciaEstado' => $this->booleano(),
        ];
    }
}
