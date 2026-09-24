<?php

namespace App\Http\Requests;

use App\Models\Programacion\TipoPeriodoProgramacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoPeriodoProgramacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoPeriodoProgramacionId;

        return [
            'TipoPeriodoProgramacionCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionCodigo')
                    ->ignore($id, 'TipoPeriodoProgramacionId'),
            ],
            'TipoPeriodoProgramacionNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionNombre')
                    ->ignore($id, 'TipoPeriodoProgramacionId'),
            ],
            // Referencial (sin CHECK en el esquema); solo validamos que sea un entero positivo.
            'TipoPeriodoProgramacionDias'        => ['nullable', 'integer', 'min:1'],
            'TipoPeriodoProgramacionDescripcion' => ['nullable', 'string', 'max:250'],
            'TipoPeriodoProgramacionEstado'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoPeriodoProgramacionCodigo.unique' => 'Ya existe un tipo de periodo de programación con ese código.',
            'TipoPeriodoProgramacionNombre.unique' => 'Ya existe un tipo de periodo de programación con ese nombre.',
        ];
    }
}
