<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Parametros de GET /asistencia-diaria/calendario y de su PDF: el establecimiento y el mes. */
class AsistenciaCalendarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eess_id' => ['required', 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')],
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'papel' => ['nullable', Rule::in(['a3', 'a4'])],
        ];
    }

    public function attributes(): array
    {
        return ['eess_id' => 'establecimiento', 'anio' => 'año', 'mes' => 'mes', 'buscar' => 'búsqueda', 'papel' => 'papel'];
    }
}
