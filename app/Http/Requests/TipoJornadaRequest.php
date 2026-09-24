<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TipoJornada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoJornadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoJornadaId;

        return [
            'TipoJornadaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoJornada::class, 'TipoJornadaCodigo')
                    ->ignore($id, 'TipoJornadaId'),
            ],
            'TipoJornadaNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TipoJornada::class, 'TipoJornadaNombre')
                    ->ignore($id, 'TipoJornadaId'),
            ],
            'TipoJornadaDescripcion' => ['nullable', 'string', 'max:250'],
            'TipoJornadaEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoJornadaCodigo.unique' => 'Ya existe un tipo de jornada con ese código.',
            'TipoJornadaNombre.unique' => 'Ya existe un tipo de jornada con ese nombre.',
        ];
    }
}
