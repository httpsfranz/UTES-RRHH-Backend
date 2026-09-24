<?php

namespace App\Http\Requests;

use App\Models\Programacion\TipoCambioTurno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoCambioTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad, true.
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('tipo')?->TipoCambioTurnoId;

        return [
            'TipoCambioTurnoCodigo' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:30',
                Rule::unique(TipoCambioTurno::class, 'TipoCambioTurnoCodigo')
                    ->ignore($id, 'TipoCambioTurnoId'),
            ],
            'TipoCambioTurnoNombre' => [
                $esCreacion ? 'required' : 'sometimes',
                'string', 'max:100',
                Rule::unique(TipoCambioTurno::class, 'TipoCambioTurnoNombre')
                    ->ignore($id, 'TipoCambioTurnoId'),
            ],
            'TipoCambioTurnoRequiereReemplazante' => ['sometimes', 'boolean'],
            'TipoCambioTurnoDescripcion'          => ['nullable', 'string', 'max:250'],
            'TipoCambioTurnoEstado'               => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'TipoCambioTurnoCodigo.unique' => 'Ya existe un tipo de cambio de turno con ese código.',
            'TipoCambioTurnoNombre.unique' => 'Ya existe un tipo de cambio de turno con ese nombre.',
        ];
    }
}
