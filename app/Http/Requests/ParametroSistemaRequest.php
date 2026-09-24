<?php

namespace App\Http\Requests;

use App\Models\Configuracion\ParametroSistema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParametroSistemaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('parametro')?->ParametroSistemaId;

        return [
            // Esta tabla NO tiene columna "Nombre": es clave/valor, no un catalogo con nombre.
            'ParametroSistemaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(ParametroSistema::class, 'ParametroSistemaCodigo')
                    ->ignore($id, 'ParametroSistemaId'),
            ],
            'ParametroSistemaValor'      => ['nullable', 'string', 'max:500'],
            'ParametroSistemaDescripcion' => ['nullable', 'string', 'max:300'],
            'ParametroSistemaEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ParametroSistemaCodigo.unique' => 'Ya existe un parámetro del sistema con ese código.',
        ];
    }
}
