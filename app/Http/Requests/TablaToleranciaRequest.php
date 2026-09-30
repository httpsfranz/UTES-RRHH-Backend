<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TablaTolerancia;

class TablaToleranciaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TablaToleranciaCodigo' => $this->codigoUnico(TablaTolerancia::class, 'TablaToleranciaCodigo', 30),
            'TablaToleranciaNombre' => $this->nombreUnico(TablaTolerancia::class, 'TablaToleranciaNombre', 100),
            'TablaToleranciaDescripcion' => $this->texto(250),
            'TablaToleranciaEstado' => $this->booleano(),
        ];
    }
}
