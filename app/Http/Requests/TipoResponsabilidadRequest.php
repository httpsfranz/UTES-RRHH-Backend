<?php

namespace App\Http\Requests;

use App\Models\Organizacion\TipoResponsabilidad;

class TipoResponsabilidadRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoResponsabilidadCodigo' => $this->codigoUnico(TipoResponsabilidad::class, 'TipoResponsabilidadCodigo', 30),
            'TipoResponsabilidadNombre' => $this->nombreUnico(TipoResponsabilidad::class, 'TipoResponsabilidadNombre', 150),
            'TipoResponsabilidadDescripcion' => $this->texto(300),
            'TipoResponsabilidadEstado' => $this->booleano(),
        ];
    }
}
