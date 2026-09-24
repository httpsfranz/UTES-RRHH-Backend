<?php

namespace App\Http\Requests;

use App\Models\Personal\Profesion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('profesion')?->ProfesionId;

        return [
            'ProfesionCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(Profesion::class, 'ProfesionCodigo')->ignore($id, 'ProfesionId'),
            ],
            'ProfesionNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(Profesion::class, 'ProfesionNombre')->ignore($id, 'ProfesionId'),
            ],
            'ProfesionDescripcion'         => ['nullable', 'string', 'max:300'],
            'ProfesionRequiereColegiatura' => ['sometimes', 'boolean'],
            'ProfesionEstado'              => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ProfesionCodigo.unique' => 'Ya existe una profesión con ese código.',
            'ProfesionNombre.unique' => 'Ya existe una profesión con ese nombre.',
        ];
    }
}
