<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Http\Requests\Concerns\ReglasDeVacaciones;
use App\Models\Vacaciones\RolVacacional;
use Illuminate\Validation\Validator;

/**
 * Cuerpo de la accion reprogramar del Rol de Vacaciones: la nueva fecha de inicio del mismo descanso (mismos dias). La
 * programacion actual queda REPROGRAMADO y nace una nueva (RIT, Art. 71: las modificaciones del rol se comunican a
 * Recursos Humanos para su control). Las reglas de fondo son las de programar un descanso.
 */
class RolVacacionalReprogramarRequest extends CatalogoRequest
{
    use ReglasDeNegocio;
    use ReglasDeVacaciones;

    public function rules(): array
    {
        return [
            'RolVacacionalFechaProgramada' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'RolVacacionalFechaProgramada.date_format' => 'La nueva fecha de inicio debe tener el formato AAAA-MM-DD.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            /** @var RolVacacional $rol */
            $rol = $this->registro();
            if ($rol->RolVacacionalEstado !== 'PROGRAMADO') {
                $validator->errors()->add('RolVacacionalFechaProgramada', 'Solo se reprograma una programación vacacional en estado PROGRAMADO.');

                return;
            }
            if ($this->tieneGocesVigentes($rol)) {
                $validator->errors()->add('RolVacacionalFechaProgramada', 'Ya tiene goces solicitados: anúlalos antes de reprogramar.');

                return;
            }

            $inicio = $this->input('RolVacacionalFechaProgramada');
            if ($inicio === $rol->RolVacacionalFechaProgramada->toDateString()) {
                $validator->errors()->add('RolVacacionalFechaProgramada', 'La nueva fecha es la misma que ya tiene programada.');

                return;
            }
            $dias = (int) $rol->RolVacacionalDias;
            $this->validarDescansoProgramado($validator, $rol->periodoVacacional()->with('vinculoLaboral')->first(), $inicio, $this->fechaFinDelDescanso($inicio, $dias), $dias, $rol->RolVacacionalId);
        });
    }
}
