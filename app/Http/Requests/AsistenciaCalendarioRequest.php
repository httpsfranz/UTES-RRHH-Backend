<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parametros de GET /asistencia-diaria/calendario y de su PDF: el establecimiento y que se ve. Dos formas:
 *   - vista (dia | semana | mes) + fecha: el dia, la semana (lunes a domingo) o el mes que contiene la fecha;
 *   - anio + mes: el mes completo (forma original, la usan el PDF y los calendarios de programacion).
 */
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
            'vista' => ['nullable', Rule::in(['dia', 'semana', 'mes'])],
            'fecha' => ['required_with:vista', 'nullable', 'date_format:Y-m-d'],
            'anio' => ['required_without:vista', 'nullable', 'integer', 'between:2000,2100'],
            'mes' => ['required_without:vista', 'nullable', 'integer', 'between:1,12'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'papel' => ['nullable', Rule::in(['a3', 'a4'])],
        ];
    }

    public function attributes(): array
    {
        return ['eess_id' => 'establecimiento', 'vista' => 'vista', 'fecha' => 'fecha', 'anio' => 'año', 'mes' => 'mes', 'buscar' => 'búsqueda', 'papel' => 'papel'];
    }
}
