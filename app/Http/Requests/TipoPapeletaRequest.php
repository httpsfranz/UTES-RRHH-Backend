<?php

namespace App\Http\Requests;

use App\Models\Solicitudes\TipoPapeleta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoPapeletaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoPapeletaId;

        return [
            'TipoPapeletaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoPapeleta::class, 'TipoPapeletaCodigo')
                    ->ignore($id, 'TipoPapeletaId'),
            ],
            'TipoPapeletaNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(TipoPapeleta::class, 'TipoPapeletaNombre')
                    ->ignore($id, 'TipoPapeletaId'),
            ],
            'TipoPapeletaDescripcion'      => ['nullable', 'string', 'max:300'],
            'TipoPapeletaEsDescontable'    => ['sometimes', 'boolean'],
            'TipoPapeletaRequiereSustento' => ['sometimes', 'boolean'],
            'TipoPapeletaAfectaJornada'    => ['sometimes', 'boolean'],
            'TipoPapeletaEsCompensable'    => ['sometimes', 'boolean'],
            'TipoPapeletaEstado'           => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoPapeletaCodigo.unique' => 'Ya existe un tipo de papeleta con ese código.',
            'TipoPapeletaNombre.unique' => 'Ya existe un tipo de papeleta con ese nombre.',
        ];
    }
}
