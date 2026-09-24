<?php

namespace App\Http\Requests;

use App\Models\Consolidacion\PeriodoAsistencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PeriodoAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');
        $id = $this->route('periodo')?->PeriodoAsistenciaId;

        return [
            'PeriodoAsistenciaAnio' => [$esCreacion ? 'required' : 'sometimes', 'integer', 'digits:4'],
            'PeriodoAsistenciaMes'  => [$esCreacion ? 'required' : 'sometimes', 'integer', 'between:1,12'],
            'PeriodoAsistenciaFechaInicio' => [$esCreacion ? 'required' : 'sometimes', 'date'],
            'PeriodoAsistenciaFechaFin'    => [
                $esCreacion ? 'required' : 'sometimes', 'date', 'after_or_equal:PeriodoAsistenciaFechaInicio',
            ],
            // El cierre real (transicion de estado) queda para un Service futuro;
            // aqui solo se permite declarar el estado dentro de los tres validos.
            'PeriodoAsistenciaEstado' => ['sometimes', Rule::in(['ABIERTO', 'EN_PROCESO', 'CERRADO'])],
        ];
    }

    public function messages(): array
    {
        return [
            'PeriodoAsistenciaFechaFin.after_or_equal' => 'La fecha fin no puede ser anterior a la fecha inicio.',
        ];
    }

    // Unicidad de (Anio, Mes) validada aqui porque es una combinacion, no una columna sola.
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $anio = $this->input('PeriodoAsistenciaAnio');
            $mes = $this->input('PeriodoAsistenciaMes');

            if (! $anio || ! $mes) {
                return;
            }

            $id = $this->route('periodo')?->PeriodoAsistenciaId;

            $existe = PeriodoAsistencia::where('PeriodoAsistenciaAnio', $anio)
                ->where('PeriodoAsistenciaMes', $mes)
                ->when($id, fn ($q) => $q->where('PeriodoAsistenciaId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('PeriodoAsistenciaMes', 'Ya existe un período para ese año y mes.');
            }
        });
    }
}
