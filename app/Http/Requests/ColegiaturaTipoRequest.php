<?php

namespace App\Http\Requests;

use App\Models\Personal\ColegiaturaTipo;
use App\Models\Personal\Profesion;

class ColegiaturaTipoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'ColegiaturaTipoCodigo' => $this->codigoUnico(ColegiaturaTipo::class, 'ColegiaturaTipoCodigo', 20),
            'ColegiaturaTipoNombre' => $this->nombreUnico(ColegiaturaTipo::class, 'ColegiaturaTipoNombre', 150),
            'ColegiaturaTipoDescripcion' => $this->texto(300),
            'ColegiaturaTipoEntidad' => $this->texto(200),
            'ProfesionId' => ['nullable', 'integer', $this->existe(Profesion::class)],
            'ColegiaturaTipoEstado' => $this->booleano(),
        ];
    }
}
