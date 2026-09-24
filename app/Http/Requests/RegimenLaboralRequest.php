<?php

namespace App\Http\Requests;

use App\Models\Personal\RegimenLaboral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegimenLaboralRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('regimen')?->RegimenLaboralId;

        return [
            'RegimenLaboralCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(RegimenLaboral::class, 'RegimenLaboralCodigo')
                    ->ignore($id, 'RegimenLaboralId'),
            ],
            'RegimenLaboralNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(RegimenLaboral::class, 'RegimenLaboralNombre')
                    ->ignore($id, 'RegimenLaboralId'),
            ],
            // No unico: distintos regimenes pueden citar la misma norma marco.
            'RegimenLaboralBaseLegal'   => ['nullable', 'string', 'max:150'],
            'RegimenLaboralDescripcion' => ['nullable', 'string', 'max:250'],
            'RegimenLaboralEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'RegimenLaboralCodigo.unique' => 'Ya existe un régimen laboral con ese código.',
            'RegimenLaboralNombre.unique' => 'Ya existe un régimen laboral con ese nombre.',
        ];
    }
}
