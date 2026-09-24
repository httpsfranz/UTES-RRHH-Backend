<?php

namespace App\Http\Requests;

use App\Models\Organizacion\TipoEstablecimiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoEstablecimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoEstablecimientoId;

        return [
            'TipoEstablecimientoCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoEstablecimiento::class, 'TipoEstablecimientoCodigo')
                    ->ignore($id, 'TipoEstablecimientoId'),
            ],
            'TipoEstablecimientoNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TipoEstablecimiento::class, 'TipoEstablecimientoNombre')
                    ->ignore($id, 'TipoEstablecimientoId'),
            ],
            'TipoEstablecimientoDescripcion' => ['nullable', 'string', 'max:250'],
            'TipoEstablecimientoEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoEstablecimientoCodigo.unique' => 'Ya existe un tipo de establecimiento con ese código.',
            'TipoEstablecimientoNombre.unique' => 'Ya existe un tipo de establecimiento con ese nombre.',
        ];
    }
}
