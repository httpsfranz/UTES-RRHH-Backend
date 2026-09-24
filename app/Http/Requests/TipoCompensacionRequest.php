<?php

namespace App\Http\Requests;

use App\Models\Compensaciones\TipoCompensacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoCompensacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoCompensacionId;

        return [
            'TipoCompensacionCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoCompensacion::class, 'TipoCompensacionCodigo')
                    ->ignore($id, 'TipoCompensacionId'),
            ],
            'TipoCompensacionNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(TipoCompensacion::class, 'TipoCompensacionNombre')
                    ->ignore($id, 'TipoCompensacionId'),
            ],
            'TipoCompensacionDescripcion' => ['nullable', 'string', 'max:300'],
            'TipoCompensacionEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoCompensacionCodigo.unique' => 'Ya existe un tipo de compensación con ese código.',
            'TipoCompensacionNombre.unique' => 'Ya existe un tipo de compensación con ese nombre.',
        ];
    }
}
