<?php

namespace App\Http\Requests;

use App\Models\Personal\GrupoOcupacional;

class GrupoOcupacionalRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'GrupoOcupacionalCodigo' => $this->codigoUnico(GrupoOcupacional::class, 'GrupoOcupacionalCodigo', 30),
            'GrupoOcupacionalNombre' => $this->nombreUnico(GrupoOcupacional::class, 'GrupoOcupacionalNombre', 100),
            'GrupoOcupacionalDescripcion' => $this->texto(250),
            'GrupoOcupacionalEstado' => $this->booleano(),
        ];
    }
}
