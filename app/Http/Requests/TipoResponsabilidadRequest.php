<?php

namespace App\Http\Requests;

use App\Models\Organizacion\TipoResponsabilidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoResponsabilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoResponsabilidadId;

        return [
            'TipoResponsabilidadCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoResponsabilidad::class, 'TipoResponsabilidadCodigo')
                    ->ignore($id, 'TipoResponsabilidadId'),
            ],
            'TipoResponsabilidadNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(TipoResponsabilidad::class, 'TipoResponsabilidadNombre')
                    ->ignore($id, 'TipoResponsabilidadId'),
            ],
            'TipoResponsabilidadDescripcion' => ['nullable', 'string', 'max:300'],
            'TipoResponsabilidadEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoResponsabilidadCodigo.unique' => 'Ya existe un tipo de responsabilidad con ese código.',
            'TipoResponsabilidadNombre.unique' => 'Ya existe un tipo de responsabilidad con ese nombre.',
        ];
    }
}
