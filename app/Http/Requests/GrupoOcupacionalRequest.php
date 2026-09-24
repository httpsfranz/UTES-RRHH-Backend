<?php

namespace App\Http\Requests;

use App\Models\Personal\GrupoOcupacional;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrupoOcupacionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('grupo')?->GrupoOcupacionalId;

        return [
            'GrupoOcupacionalCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(GrupoOcupacional::class, 'GrupoOcupacionalCodigo')
                    ->ignore($id, 'GrupoOcupacionalId'),
            ],
            'GrupoOcupacionalNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(GrupoOcupacional::class, 'GrupoOcupacionalNombre')
                    ->ignore($id, 'GrupoOcupacionalId'),
            ],
            'GrupoOcupacionalDescripcion' => ['nullable', 'string', 'max:250'],
            'GrupoOcupacionalEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'GrupoOcupacionalCodigo.unique' => 'Ya existe un grupo ocupacional con ese código.',
            'GrupoOcupacionalNombre.unique' => 'Ya existe un grupo ocupacional con ese nombre.',
        ];
    }
}
