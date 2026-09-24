<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Permiso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('permiso')?->PermisoId;

        return [
            'PermisoCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(Permiso::class, 'PermisoCodigo')
                    ->ignore($id, 'PermisoId'),
            ],
            'PermisoNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(Permiso::class, 'PermisoNombre')
                    ->ignore($id, 'PermisoId'),
            ],
            'PermisoModulo'      => ['nullable', 'string', 'max:60'],
            'PermisoDescripcion' => ['nullable', 'string', 'max:300'],
            'PermisoEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'PermisoCodigo.unique' => 'Ya existe un permiso con ese código.',
            'PermisoNombre.unique' => 'Ya existe un permiso con ese nombre.',
        ];
    }
}
