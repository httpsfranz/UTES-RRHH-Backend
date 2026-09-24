<?php

namespace App\Http\Requests;

use App\Models\Biometria\MetodoMarcacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MetodoMarcacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('metodo')?->MetodoMarcacionId;

        return [
            'MetodoMarcacionCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:50',
                Rule::unique(MetodoMarcacion::class, 'MetodoMarcacionCodigo')
                    ->ignore($id, 'MetodoMarcacionId'),
            ],
            'MetodoMarcacionNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(MetodoMarcacion::class, 'MetodoMarcacionNombre')
                    ->ignore($id, 'MetodoMarcacionId'),
            ],
            'MetodoMarcacionDescripcion' => ['nullable', 'string', 'max:250'],
            'MetodoMarcacionEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'MetodoMarcacionCodigo.unique' => 'Ya existe un método de marcación con ese código.',
            'MetodoMarcacionNombre.unique' => 'Ya existe un método de marcación con ese nombre.',
        ];
    }
}
