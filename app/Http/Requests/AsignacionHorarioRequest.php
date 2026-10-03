<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Configuracion\Horario;
use App\Models\Personal\AsignacionHorario;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Validation\Validator;

/**
 * Asignacion de horario: que horario cumple un vinculo desde cuando (RIT, Art. 16). Conserva el historico: cambiar
 * de horario es cerrar la asignacion vigente (fecha de fin) y crear otra. Un vinculo tiene un solo horario vigente.
 */
class AsignacionHorarioRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'HorarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Horario::class, 'HorarioEstado', 'HorarioId')],
            'AsignacionHorarioFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'AsignacionHorarioFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'AsignacionHorarioObservacion' => $this->texto(500),
            'AsignacionHorarioEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'AsignacionHorarioFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'AsignacionHorarioFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - fin >= inicio; el vinculo vigente durante la asignacion.
     * - Un horario propio de un establecimiento solo se asigna a quien trabaja en ese establecimiento (los horarios
     *   de toda la Red se asignan a cualquiera).
     * - Un solo horario vigente por vinculo: no se superpone con otra asignacion activa (tampoco al reactivarla).
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('AsignacionHorarioFechaInicio');
            $fin = $this->fechaEfectiva('AsignacionHorarioFechaFin');
            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('AsignacionHorarioFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'VinculoLaboralId', $inicio, $fin ?? $inicio);

            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            $horario = Horario::query()->find($this->valorEfectivo('HorarioId'));
            if ($vinculo && $horario && $horario->EessId !== null && $horario->EessId !== $vinculo->EessId) {
                $validator->errors()->add('HorarioId', 'Ese horario es propio de otro establecimiento: no corresponde al del trabajador.');
            }

            if ($this->booleanoEfectivo('AsignacionHorarioEstado')) {
                $solapa = $this->haySuperposicion(
                    AsignacionHorario::class, 'AsignacionHorarioFechaInicio', 'AsignacionHorarioFechaFin', $inicio, $fin,
                    ['VinculoLaboralId' => $this->valorEfectivo('VinculoLaboralId'), 'AsignacionHorarioEstado' => 1],
                );
                if ($solapa) {
                    $validator->errors()->add('AsignacionHorarioFechaInicio', 'El trabajador ya tiene un horario asignado en esas fechas: cierra la asignación vigente antes de crear otra.');
                }
            }
        });
    }
}
