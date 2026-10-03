<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cuerpo de POST /compensaciones-horarias/{id}/devolver: las horas que el trabajador devuelve. */
class CompensacionDevolverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad (M04), true.
        return true;
    }

    public function rules(): array
    {
        return [
            'Horas' => ['required', 'numeric', 'gt:0', 'max:24', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function attributes(): array
    {
        return ['Horas' => 'horas devueltas'];
    }

    public function messages(): array
    {
        return [
            'Horas.gt' => 'Las horas devueltas deben ser mayores que cero.',
            'Horas.regex' => 'Las horas admiten hasta 2 decimales (por ejemplo 1.5).',
        ];
    }
}
