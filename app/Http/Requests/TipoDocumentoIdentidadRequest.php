<?php

namespace App\Http\Requests;

use App\Models\Personal\TipoDocumentoIdentidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoDocumentoIdentidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoDocumentoIdentidadId;

        return [
            'TipoDocumentoIdentidadCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:20',
                Rule::unique(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadCodigo')
                    ->ignore($id, 'TipoDocumentoIdentidadId'),
            ],
            'TipoDocumentoIdentidadNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadNombre')
                    ->ignore($id, 'TipoDocumentoIdentidadId'),
            ],
            'TipoDocumentoIdentidadAbreviatura' => ['nullable', 'string', 'max:20'],

            // TINYINT en SQL Server: 0-255. La longitud real de un documento (DNI=8,
            // RUC=11, CE=9...) siempre cabe holgadamente en ese rango.
            'TipoDocumentoIdentidadLongitud' => ['nullable', 'integer', 'min:1', 'max:20'],
            'TipoDocumentoIdentidadEstado'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoDocumentoIdentidadCodigo.unique' => 'Ya existe un tipo de documento con ese código.',
            'TipoDocumentoIdentidadNombre.unique' => 'Ya existe un tipo de documento con ese nombre.',
        ];
    }
}
