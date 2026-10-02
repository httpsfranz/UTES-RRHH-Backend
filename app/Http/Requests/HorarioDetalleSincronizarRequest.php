<?php

namespace App\Http\Requests;

use App\Models\Configuracion\Turno;
use Illuminate\Validation\Validator;

/**
 * Reemplaza la grilla semanal completa de un horario. `Detalle` es la lista COMPLETA que debe quedar
 * (vacia = horario sin dias asignados). El orden de los turnos de un mismo dia es opcional: si falta,
 * el servicio los ordena por hora de entrada.
 */
class HorarioDetalleSincronizarRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'Detalle' => ['present', 'array', 'max:70'],
            'Detalle.*' => ['array'],
            'Detalle.*.TurnoId' => ['required', 'integer', $this->existeActivo(Turno::class, 'TurnoEstado', 'TurnoId')],
            'Detalle.*.HorarioDetalleDia' => ['required', 'integer', 'between:1,7'],
            'Detalle.*.HorarioDetalleOrden' => ['nullable', 'integer', 'between:1,10'],
            'Detalle.*.HorarioDetalleEsDescanso' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'Detalle' => 'detalle',
            'Detalle.*.TurnoId' => 'turno',
            'Detalle.*.HorarioDetalleDia' => 'día',
            'Detalle.*.HorarioDetalleOrden' => 'orden',
            'Detalle.*.HorarioDetalleEsDescanso' => 'descanso',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $horario = $this->registro();
            if ($horario && ! $horario->HorarioEstado) {
                $validator->errors()->add('Detalle', 'El horario está inactivo: reactívalo antes de modificar su detalle.');

                return;
            }

            $vistos = [];
            $ordenes = [];
            foreach ($this->input('Detalle', []) as $indice => $fila) {
                $clave = $fila['HorarioDetalleDia'].'-'.$fila['TurnoId'];
                if (isset($vistos[$clave])) {
                    $validator->errors()->add("Detalle.{$indice}.TurnoId", 'El mismo turno está repetido en el mismo día.');
                }
                $vistos[$clave] = true;

                if (! empty($fila['HorarioDetalleOrden'])) {
                    $claveOrden = $fila['HorarioDetalleDia'].'-'.$fila['HorarioDetalleOrden'];
                    if (isset($ordenes[$claveOrden])) {
                        $validator->errors()->add("Detalle.{$indice}.HorarioDetalleOrden", 'Hay dos turnos con el mismo orden en el mismo día.');
                    }
                    $ordenes[$claveOrden] = true;
                }
            }
        });
    }
}
