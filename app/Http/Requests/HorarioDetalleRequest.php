<?php

namespace App\Http\Requests;

use App\Models\Configuracion\Horario;
use App\Models\Configuracion\HorarioDetalle;
use App\Models\Configuracion\Turno;
use Illuminate\Validation\Validator;

class HorarioDetalleRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'HorarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Horario::class, 'HorarioEstado', 'HorarioId')],
            'TurnoId' => [$this->obligatorio(), 'integer', $this->existeActivo(Turno::class, 'TurnoEstado', 'TurnoId')],
            // 1 = lunes ... 7 = domingo (ISO-8601).
            'HorarioDetalleDia' => [$this->obligatorio(), 'integer', 'between:1,7'],
            // Si no se envia al crear, el controlador lo pone despues de los turnos que ya tiene ese dia.
            'HorarioDetalleOrden' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'HorarioDetalleEsDescanso' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'HorarioDetalleDia.between' => 'El día debe estar entre 1 (lunes) y 7 (domingo).',
        ];
    }

    /** UNIQUE (horario, dia, turno) y, ademas, que no haya dos turnos con el mismo orden el mismo dia. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $otros = HorarioDetalle::query()
                ->where('HorarioId', $this->valorEfectivo('HorarioId'))
                ->where('HorarioDetalleDia', $this->valorEfectivo('HorarioDetalleDia'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('HorarioDetalleId', '!=', $id));

            if ((clone $otros)->where('TurnoId', $this->valorEfectivo('TurnoId'))->exists()) {
                $validator->errors()->add('TurnoId', 'Ese turno ya está asignado a ese día del horario.');

                return;
            }

            $orden = $this->valorEfectivo('HorarioDetalleOrden');
            if ($orden !== null && (clone $otros)->where('HorarioDetalleOrden', $orden)->exists()) {
                $validator->errors()->add('HorarioDetalleOrden', 'Ya hay otro turno con ese orden en ese día.');
            }
        });
    }
}
