<?php

namespace App\Http\Requests;

use App\Models\Personal\ColegiaturaTipo;
use App\Models\Personal\Profesion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ColegiaturaTipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->ColegiaturaTipoId;

        return [
            // Opcional: no todo tipo de colegiatura esta atado a una sola profesion.
            'ProfesionId' => ['nullable', 'integer', Rule::exists(Profesion::class, 'ProfesionId')],
            'ColegiaturaTipoCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:20',
                Rule::unique(ColegiaturaTipo::class, 'ColegiaturaTipoCodigo')->ignore($id, 'ColegiaturaTipoId'),
            ],
            'ColegiaturaTipoNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(ColegiaturaTipo::class, 'ColegiaturaTipoNombre')->ignore($id, 'ColegiaturaTipoId'),
            ],
            'ColegiaturaTipoEntidad'     => ['nullable', 'string', 'max:200'],
            'ColegiaturaTipoDescripcion' => ['nullable', 'string', 'max:300'],
            'ColegiaturaTipoEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ProfesionId.exists'            => 'La profesión indicada no existe.',
            'ColegiaturaTipoCodigo.unique'  => 'Ya existe un tipo de colegiatura con ese código.',
            'ColegiaturaTipoNombre.unique'  => 'Ya existe un tipo de colegiatura con ese nombre.',
        ];
    }
}
