<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Vacaciones\PeriodoVacacional;
use Illuminate\Validation\Validator;

/**
 * Periodo vacacional (record) de un vinculo. RIT, Art. 68: el servidor tiene derecho a 30 dias calendario de descanso
 * remunerado por cada anio completo de servicios; los dias disponibles son los ganados menos los gozados.
 */
class PeriodoVacacionalRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Dias de descanso vacacional por anio completo de servicios (RIT, Art. 68). */
    public const DIAS_POR_ANIO = 30;

    /** ANULADO no se envia: se llega con DELETE. */
    public const ESTADOS_EDITABLES = ['ABIERTO', 'CERRADO'];

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'PeriodoVacacionalAnio' => [$this->obligatorio(), 'integer', 'between:2000,2100'],
            'PeriodoVacacionalFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'PeriodoVacacionalFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'PeriodoVacacionalDiasGanados' => ['sometimes', 'numeric', 'between:0,'.self::DIAS_POR_ANIO, 'regex:/^\d+(\.\d{1,2})?$/'],
            // Si no se envia al crear, quedan igual a los ganados (aun no se goza ninguno).
            'PeriodoVacacionalDiasDisponibles' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'PeriodoVacacionalEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            'PeriodoVacacionalAnio.between' => 'El año debe estar entre 2000 y 2100.',
            'PeriodoVacacionalFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'PeriodoVacacionalFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
            'PeriodoVacacionalDiasGanados.between' => 'Los días ganados van de 0 a '.self::DIAS_POR_ANIO.' por año completo de servicios (RIT, Art. 68).',
            '*.regex' => 'Los días admiten hasta 2 decimales (por ejemplo 7.5).',
            'PeriodoVacacionalEstado.in' => 'El estado debe ser ABIERTO o CERRADO (para anular, elimina el período).',
        ];
    }

    /**
     * - fin >= inicio; un periodo por vinculo y anio; el vinculo ya existia al iniciar el periodo.
     * - Los dias disponibles no superan los ganados; un periodo cerrado o anulado ya no se modifica.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $actual = $this->registro()?->PeriodoVacacionalEstado;
            $reabre = $actual === 'CERRADO' && $this->input('PeriodoVacacionalEstado') === 'ABIERTO' && count($this->all()) === 1;
            if (in_array($actual, ['CERRADO', 'ANULADO'], true) && ! $reabre) {
                $validator->errors()->add('PeriodoVacacionalEstado', 'El período vacacional está '.($actual === 'CERRADO' ? 'cerrado' : 'anulado').' y ya no se puede modificar.');

                return;
            }

            $inicio = $this->fechaEfectiva('PeriodoVacacionalFechaInicio');
            $fin = $this->fechaEfectiva('PeriodoVacacionalFechaFin');
            if ($fin < $inicio) {
                $validator->errors()->add('PeriodoVacacionalFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            if ($vinculo && $inicio < $vinculo->VinculoLaboralFechaInicio->toDateString()) {
                $validator->errors()->add('PeriodoVacacionalFechaInicio', 'El período no puede iniciar antes del vínculo laboral ('.$this->fechaCorta($vinculo->VinculoLaboralFechaInicio->toDateString()).').');
            }

            $existe = PeriodoVacacional::query()
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->where('PeriodoVacacionalAnio', $this->valorEfectivo('PeriodoVacacionalAnio'))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('PeriodoVacacionalAnio', 'Ese trabajador ya tiene su período vacacional de ese año.');
            }

            $ganados = (float) ($this->valorEfectivo('PeriodoVacacionalDiasGanados') ?? self::DIAS_POR_ANIO);
            $disponibles = $this->valorEfectivo('PeriodoVacacionalDiasDisponibles');
            if ($disponibles !== null && (float) $disponibles > $ganados) {
                $validator->errors()->add('PeriodoVacacionalDiasDisponibles', 'Los días disponibles no pueden superar los días ganados.');
            }
        });
    }

    public function datos(): array
    {
        $datos = $this->validated();

        // Disponibles vacio = todos los ganados (los 30 por defecto) al crear; al editar los ganados, si ya no alcanzan, se ajustan.
        if ($this->esCreacion()) {
            $ganados = (float) ($datos['PeriodoVacacionalDiasGanados'] ?? self::DIAS_POR_ANIO);
            $datos['PeriodoVacacionalDiasGanados'] = $ganados;
            if (blank($datos['PeriodoVacacionalDiasDisponibles'] ?? null)) {
                $datos['PeriodoVacacionalDiasDisponibles'] = $ganados;
            }
        } elseif (array_key_exists('PeriodoVacacionalDiasDisponibles', $datos) && $datos['PeriodoVacacionalDiasDisponibles'] === null) {
            unset($datos['PeriodoVacacionalDiasDisponibles']);
        }

        return $datos;
    }
}
