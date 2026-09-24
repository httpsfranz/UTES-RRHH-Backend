<?php

namespace App\Http\Requests;

use App\Models\Disciplina\TipoFaltaDisciplinaria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoFaltaDisciplinariaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoFaltaDisciplinariaId;

        return [
            'TipoFaltaDisciplinariaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:50',
                Rule::unique(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaCodigo')
                    ->ignore($id, 'TipoFaltaDisciplinariaId'),
            ],
            'TipoFaltaDisciplinariaNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaNombre')
                    ->ignore($id, 'TipoFaltaDisciplinariaId'),
            ],

            // CHECK (Gravedad IN ('LEVE','GRAVE','MUY_GRAVE')) traducido con Rule::in.
            'TipoFaltaDisciplinariaGravedad' => ['nullable', Rule::in(['LEVE', 'GRAVE', 'MUY_GRAVE'])],
            'TipoFaltaDisciplinariaBaseLegal'   => ['nullable', 'string', 'max:200'],
            'TipoFaltaDisciplinariaDescripcion' => ['nullable', 'string', 'max:300'],
            'TipoFaltaDisciplinariaEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoFaltaDisciplinariaCodigo.unique' => 'Ya existe un tipo de falta disciplinaria con ese código.',
            'TipoFaltaDisciplinariaNombre.unique' => 'Ya existe un tipo de falta disciplinaria con ese nombre.',
            'TipoFaltaDisciplinariaGravedad.in'   => 'La gravedad debe ser LEVE, GRAVE o MUY_GRAVE.',
        ];
    }
}
