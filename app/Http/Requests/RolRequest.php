<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RolRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('rol')?->RolId;

        return [
            'RolCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:50',
                Rule::unique(Rol::class, 'RolCodigo')
                    ->ignore($id, 'RolId'),
            ],
            'RolNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(Rol::class, 'RolNombre')
                    ->ignore($id, 'RolId'),
            ],
            'RolDescripcion' => ['nullable', 'string', 'max:300'],
            'RolEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'RolCodigo.unique' => 'Ya existe un rol con ese código.',
            'RolNombre.unique' => 'Ya existe un rol con ese nombre.',
        ];
    }
}
