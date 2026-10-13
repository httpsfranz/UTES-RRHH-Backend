<?php

namespace App\Http\Requests;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Cuerpo de POST /asistencia-diaria/calcular: el rango de fechas y, opcionalmente, un vinculo o un establecimiento. */
class AsistenciaCalcularRequest extends FormRequest
{
    /** Un rango mayor se parte en varias corridas: el calculo es por lotes de vinculos y dias. */
    public const MAXIMO_DIAS = 62;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'FechaInicio' => ['required', 'date_format:Y-m-d'],
            'FechaFin' => ['required', 'date_format:Y-m-d', 'after_or_equal:FechaInicio'],
            'VinculoLaboralId' => ['nullable', 'integer', Rule::exists(VinculoLaboral::class, 'VinculoLaboralId')],
            'EessId' => ['nullable', 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')],
            'SobrescribirManuales' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $dias = (strtotime($this->input('FechaFin')) - strtotime($this->input('FechaInicio'))) / 86400 + 1;
            if ($dias > self::MAXIMO_DIAS) {
                $validator->errors()->add('FechaFin', 'El rango no puede pasar de '.self::MAXIMO_DIAS.' días: calcula por mes.');
            }
        });
    }

    public function attributes(): array
    {
        return ['FechaInicio' => 'fecha de inicio', 'FechaFin' => 'fecha de fin', 'VinculoLaboralId' => 'vínculo laboral', 'EessId' => 'establecimiento', 'SobrescribirManuales' => 'sobrescribir manuales'];
    }
}
