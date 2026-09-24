<?php

namespace App\Http\Requests;

use App\Models\Personal\CondicionLaboral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CondicionLaboralRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('condicion')?->CondicionLaboralId;

        return [
            'CondicionLaboralCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(CondicionLaboral::class, 'CondicionLaboralCodigo')
                    ->ignore($id, 'CondicionLaboralId'),
            ],
            'CondicionLaboralNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(CondicionLaboral::class, 'CondicionLaboralNombre')
                    ->ignore($id, 'CondicionLaboralId'),
            ],
            'CondicionLaboralDescripcion'     => ['nullable', 'string', 'max:250'],

            // BIT NOT NULL con DEFAULT (0): si no llegan, la base aplica el default.
            'CondicionLaboralEsPermanente'    => ['sometimes', 'boolean'],
            'CondicionLaboralRequiereAirhsp'  => ['sometimes', 'boolean'],
            'CondicionLaboralEstado'          => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'CondicionLaboralCodigo.unique' => 'Ya existe una condición laboral con ese código.',
            'CondicionLaboralNombre.unique' => 'Ya existe una condición laboral con ese nombre.',
        ];
    }
}
