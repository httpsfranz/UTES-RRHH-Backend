<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Http\Requests\Concerns\ReglasDeVacaciones;
use App\Models\Vacaciones\PeriodoVacacional;
use Illuminate\Validation\Validator;

/**
 * Programacion del goce de un periodo vacacional (Rol de Vacaciones). RIT, Art. 68 a 74: 30 dias calendario por anio
 * completo; el descanso es preferentemente continuo, y puede fraccionarse en tramos de 7 dias o mas (hasta 7 dias en
 * tramos menores). El ultimo dia lo calcula el sistema; el estado cambia con reprogramar, anular o al aprobar el goce.
 */
class RolVacacionalRequest extends CatalogoRequest
{
    use ReglasDeNegocio;
    use ReglasDeVacaciones;

    public function rules(): array
    {
        return [
            'PeriodoVacacionalId' => [$this->obligatorio(), 'integer', $this->existe(PeriodoVacacional::class)],
            'RolVacacionalFechaProgramada' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'RolVacacionalFechaFinProgramada' => ['nullable', 'date_format:Y-m-d'],
            'RolVacacionalDias' => [$this->obligatorio(), 'integer', 'between:1,'.PeriodoVacacionalRequest::DIAS_POR_ANIO],
        ];
    }

    public function messages(): array
    {
        return [
            'RolVacacionalFechaProgramada.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'RolVacacionalFechaFinProgramada.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
            'RolVacacionalDias.integer' => 'Los días del descanso son días calendario enteros.',
            'RolVacacionalDias.between' => 'El descanso va de 1 a '.PeriodoVacacionalRequest::DIAS_POR_ANIO.' días calendario.',
        ];
    }

    /**
     * - Solo se modifica una programacion PROGRAMADA y sin goces vigentes; el estado no se envia.
     * - La fecha de fin, si se envia, es la que resulta de los dias; el resto de reglas en ReglasDeVacaciones.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'RolVacacionalEstado');
            $registro = $this->registro();
            if ($registro) {
                if ($registro->RolVacacionalEstado !== 'PROGRAMADO') {
                    $validator->errors()->add('RolVacacionalEstado', 'La programación vacacional está '.match ($registro->RolVacacionalEstado) {
                        'GOZADO' => 'gozada', 'REPROGRAMADO' => 'reprogramada', default => 'anulada',
                    }.' y ya no se puede modificar.');
                } elseif ($this->tieneGocesVigentes($registro)) {
                    $validator->errors()->add('RolVacacionalEstado', 'Ya tiene goces solicitados: anúlalos antes de modificar la programación.');
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $periodo = PeriodoVacacional::query()->with('vinculoLaboral')->find($this->valorEfectivo('PeriodoVacacionalId'));
            $inicio = $this->fechaEfectiva('RolVacacionalFechaProgramada');
            $dias = (int) $this->valorEfectivo('RolVacacionalDias');
            $fin = $this->fechaFinDelDescanso($inicio, $dias);

            $enviado = $this->input('RolVacacionalFechaFinProgramada');
            if ($enviado !== null && $enviado !== $fin) {
                $validator->errors()->add('RolVacacionalFechaFinProgramada', 'Con '.$dias.' días desde el '.$this->fechaCorta($inicio).' el descanso termina el '.$this->fechaCorta($fin).'.');

                return;
            }

            $this->validarDescansoProgramado($validator, $periodo, $inicio, $fin, $dias, $this->registroId());
        });
    }

    public function datos(): array
    {
        $datos = $this->validated();
        $inicio = $datos['RolVacacionalFechaProgramada'] ?? $this->fechaEfectiva('RolVacacionalFechaProgramada');
        $dias = (int) ($datos['RolVacacionalDias'] ?? $this->valorEfectivo('RolVacacionalDias'));
        $datos['RolVacacionalFechaFinProgramada'] = $this->fechaFinDelDescanso($inicio, $dias);

        return $datos;
    }
}
