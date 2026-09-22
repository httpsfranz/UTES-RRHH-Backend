<?php

namespace App\Http\Requests;

use App\Models\Asistencia\ConceptoJustificacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConceptoJustificacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad (M04), true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('concepto')?->ConceptoJustificacionId;

        return [
            'ConceptoJustificacionCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(ConceptoJustificacion::class, 'ConceptoJustificacionCodigo')
                    ->ignore($id, 'ConceptoJustificacionId'),
            ],
            'ConceptoJustificacionNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:150',
                Rule::unique(ConceptoJustificacion::class, 'ConceptoJustificacionNombre')
                    ->ignore($id, 'ConceptoJustificacionId'),
            ],
            'ConceptoJustificacionDescripcion' => ['nullable', 'string', 'max:300'],

            // BIT NOT NULL con DEFAULT (1): si no llegan, la base aplica el default.
            'ConceptoJustificacionRequiereDocumento' => ['sometimes', 'boolean'],
            'ConceptoJustificacionEsRemunerado'      => ['sometimes', 'boolean'],
            'ConceptoJustificacionEstado'            => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ConceptoJustificacionCodigo.unique' => 'Ya existe un concepto de justificación con ese código.',
            'ConceptoJustificacionNombre.unique' => 'Ya existe un concepto de justificación con ese nombre.',
        ];
    }
}
