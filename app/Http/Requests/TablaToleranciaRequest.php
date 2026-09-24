<?php

namespace App\Http\Requests;

use App\Models\Configuracion\TablaTolerancia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TablaToleranciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tabla')?->TablaToleranciaId;

        return [
            'TablaToleranciaCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TablaTolerancia::class, 'TablaToleranciaCodigo')
                    ->ignore($id, 'TablaToleranciaId'),
            ],
            'TablaToleranciaNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TablaTolerancia::class, 'TablaToleranciaNombre')
                    ->ignore($id, 'TablaToleranciaId'),
            ],
            'TablaToleranciaDescripcion' => ['nullable', 'string', 'max:250'],
            'TablaToleranciaEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TablaToleranciaCodigo.unique' => 'Ya existe una tabla de tolerancia con ese código.',
            'TablaToleranciaNombre.unique' => 'Ya existe una tabla de tolerancia con ese nombre.',
        ];
    }
}
