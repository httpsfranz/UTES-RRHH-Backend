<?php

namespace App\Http\Requests;

use App\Models\Solicitudes\TipoLicencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoLicenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoLicenciaId;

        return [
            'TipoLicenciaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoLicencia::class, 'TipoLicenciaCodigo')
                    ->ignore($id, 'TipoLicenciaId'),
            ],
            'TipoLicenciaNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(TipoLicencia::class, 'TipoLicenciaNombre')
                    ->ignore($id, 'TipoLicenciaId'),
            ],
            'TipoLicenciaDescripcion' => ['nullable', 'string', 'max:300'],
            // BIT NOT NULL con DEFAULT (1): si no llega, la base aplica el default.
            'TipoLicenciaConGoce'    => ['sometimes', 'boolean'],
            'TipoLicenciaMaximoDias' => ['nullable', 'integer', 'min:1'],
            'TipoLicenciaBaseLegal'  => ['nullable', 'string', 'max:200'],
            'TipoLicenciaEstado'     => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoLicenciaCodigo.unique' => 'Ya existe un tipo de licencia con ese código.',
            'TipoLicenciaNombre.unique' => 'Ya existe un tipo de licencia con ese nombre.',
        ];
    }
}
