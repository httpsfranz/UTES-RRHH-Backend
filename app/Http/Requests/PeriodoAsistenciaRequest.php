<?php

namespace App\Http\Requests;

use App\Models\Consolidacion\PeriodoAsistencia;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PeriodoAsistenciaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'PeriodoAsistenciaAnio' => [$this->obligatorio(), 'integer', 'between:2000,2100'],
            'PeriodoAsistenciaMes' => [$this->obligatorio(), 'integer', 'between:1,12'],
            'PeriodoAsistenciaFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'PeriodoAsistenciaFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            // El cierre real (transicion de estado) queda para un Service futuro;
            // aqui solo se permite declarar el estado dentro de los tres validos.
            'PeriodoAsistenciaEstado' => ['sometimes', Rule::in(['ABIERTO', 'EN_PROCESO', 'CERRADO'])],
        ];
    }

    public function messages(): array
    {
        return [
            'PeriodoAsistenciaEstado.in' => 'El estado debe ser ABIERTO, EN_PROCESO o CERRADO.',
        ];
    }

    /**
     * Validaciones que cruzan columnas (fin >= inicio, UNIQUE año+mes). Usan el valor que
     * quedara guardado, no solo el enviado: un PATCH parcial tambien debe respetarlas.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fecha($this->valorEfectivo('PeriodoAsistenciaFechaInicio'));
            $fin = $this->fecha($this->valorEfectivo('PeriodoAsistenciaFechaFin'));

            if ($inicio && $fin && $fin < $inicio) {
                $validator->errors()->add('PeriodoAsistenciaFechaFin', 'La fecha fin no puede ser anterior a la fecha inicio.');
            }

            $existe = PeriodoAsistencia::query()
                ->where('PeriodoAsistenciaAnio', $this->valorEfectivo('PeriodoAsistenciaAnio'))
                ->where('PeriodoAsistenciaMes', $this->valorEfectivo('PeriodoAsistenciaMes'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('PeriodoAsistenciaId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('PeriodoAsistenciaMes', 'Ya existe un período para ese año y mes.');
            }
        });
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor;
    }
}
