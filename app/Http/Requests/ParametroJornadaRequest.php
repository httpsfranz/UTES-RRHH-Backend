<?php

namespace App\Http\Requests;

use App\Models\Configuracion\ParametroJornada;
use App\Models\Configuracion\TipoJornada;
use Illuminate\Validation\Validator;

class ParametroJornadaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoJornadaId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoJornada::class, 'TipoJornadaEstado', 'TipoJornadaId'),
            ],
            'ParametroJornadaVigenciaDesde' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ParametroJornadaVigenciaHasta' => ['nullable', 'date_format:Y-m-d'],
            // DECIMAL(5,2), DECIMAL(6,2), DECIMAL(7,2)
            'ParametroJornadaHorasDiarias' => [$this->obligatorio(), 'numeric', 'decimal:0,2', 'between:0,24'],
            'ParametroJornadaHorasSemanales' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,168'],
            'ParametroJornadaHorasMensuales' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,744'],
            'ParametroJornadaEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'ParametroJornadaHorasDiarias.between' => 'Las horas diarias deben estar entre 0 y 24.',
            'ParametroJornadaHorasSemanales.between' => 'Las horas semanales deben estar entre 0 y 168.',
            'ParametroJornadaHorasMensuales.between' => 'Las horas mensuales deben estar entre 0 y 744.',
        ];
    }

    /**
     * Reglas entre columnas: hasta >= desde, semanales >= diarias, mensuales >= semanales, UNIQUE
     * (jornada, desde) y que las vigencias de una misma jornada no se superpongan (si lo hicieran,
     * no habria forma de saber que parametro rige un dia dado).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $jornada = $this->valorEfectivo('TipoJornadaId');
            $desde = $this->fecha($this->valorEfectivo('ParametroJornadaVigenciaDesde'));
            $hasta = $this->fecha($this->valorEfectivo('ParametroJornadaVigenciaHasta'));
            $diarias = $this->valorEfectivo('ParametroJornadaHorasDiarias');
            $semanales = $this->valorEfectivo('ParametroJornadaHorasSemanales');
            $mensuales = $this->valorEfectivo('ParametroJornadaHorasMensuales');

            if ($hasta !== null && $hasta < $desde) {
                $validator->errors()->add('ParametroJornadaVigenciaHasta', 'La vigencia hasta no puede ser anterior a la vigencia desde.');
            }
            if ($semanales !== null && (float) $semanales < (float) $diarias) {
                $validator->errors()->add('ParametroJornadaHorasSemanales', 'Las horas semanales no pueden ser menores que las diarias.');
            }
            if ($mensuales !== null && $semanales !== null && (float) $mensuales < (float) $semanales) {
                $validator->errors()->add('ParametroJornadaHorasMensuales', 'Las horas mensuales no pueden ser menores que las semanales.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $otros = ParametroJornada::query()
                ->where('TipoJornadaId', $jornada)
                ->when($this->registroId(), fn ($q, $id) => $q->where('ParametroJornadaId', '!=', $id));

            if ((clone $otros)->whereDate('ParametroJornadaVigenciaDesde', $desde)->exists()) {
                $validator->errors()->add('ParametroJornadaVigenciaDesde', 'Ya existe un parámetro de esa jornada con la misma vigencia desde.');

                return;
            }

            // Solo cuentan los parametros activos (uno dado de baja no rige).
            $solapa = (clone $otros)->where('ParametroJornadaEstado', 1)
                ->whereDate('ParametroJornadaVigenciaDesde', '<=', $hasta ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('ParametroJornadaVigenciaHasta')->orWhereDate('ParametroJornadaVigenciaHasta', '>=', $desde))
                ->exists();

            $activo = filter_var($this->valorEfectivo('ParametroJornadaEstado') ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($solapa && $activo) {
                $validator->errors()->add('ParametroJornadaVigenciaDesde', 'La vigencia se superpone con otro parámetro activo de la misma jornada.');
            }
        });
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : ($valor === null ? null : (string) $valor);
    }
}
