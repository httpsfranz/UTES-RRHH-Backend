<?php

namespace App\Http\Requests;

use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cuerpo de POST /consolidados-asistencia/generar: el periodo y, opcionalmente, un vinculo o un establecimiento. */
class ConsolidadoGenerarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad (M04), true.
        return true;
    }

    public function rules(): array
    {
        return [
            'PeriodoAsistenciaId' => ['required', 'integer', Rule::exists(PeriodoAsistencia::class, 'PeriodoAsistenciaId')],
            'VinculoLaboralId' => ['nullable', 'integer', Rule::exists(VinculoLaboral::class, 'VinculoLaboralId')],
            'EessId' => ['nullable', 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')],
        ];
    }

    public function attributes(): array
    {
        return ['PeriodoAsistenciaId' => 'período de asistencia', 'VinculoLaboralId' => 'vínculo laboral', 'EessId' => 'establecimiento'];
    }
}
