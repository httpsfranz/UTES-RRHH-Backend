<?php

namespace App\Http\Requests;

use App\Models\Personal\Profesion;

class ProfesionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'ProfesionCodigo' => $this->codigoUnico(Profesion::class, 'ProfesionCodigo', 30),
            'ProfesionNombre' => $this->nombreUnico(Profesion::class, 'ProfesionNombre', 150),
            'ProfesionDescripcion' => $this->texto(300),
            'ProfesionRequiereColegiatura' => $this->booleano(),
            'ProfesionEstado' => $this->booleano(),
        ];
    }
}
