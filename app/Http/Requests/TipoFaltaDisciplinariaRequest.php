<?php

namespace App\Http\Requests;

use App\Models\Disciplina\TipoFaltaDisciplinaria;
use Illuminate\Validation\Rule;

class TipoFaltaDisciplinariaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoFaltaDisciplinariaCodigo' => $this->codigoUnico(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaCodigo', 50),
            'TipoFaltaDisciplinariaNombre' => $this->nombreUnico(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaNombre', 150),
            'TipoFaltaDisciplinariaDescripcion' => $this->texto(300),
            'TipoFaltaDisciplinariaGravedad' => ['nullable', Rule::in(['LEVE', 'GRAVE', 'MUY_GRAVE'])],
            'TipoFaltaDisciplinariaBaseLegal' => $this->texto(200),
            'TipoFaltaDisciplinariaEstado' => $this->booleano(),
        ];
    }
}
