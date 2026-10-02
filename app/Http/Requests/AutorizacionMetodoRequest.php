<?php

namespace App\Http\Requests;

use App\Models\Biometria\AutorizacionMetodo;
use App\Models\Biometria\MetodoMarcacion;
use App\Models\Personal\Trabajador;
use Illuminate\Validation\Validator;

class AutorizacionMetodoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TrabajadorId' => [$this->obligatorio(), 'integer', $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId')],
            'MetodoMarcacionId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(MetodoMarcacion::class, 'MetodoMarcacionEstado', 'MetodoMarcacionId'),
            ],
            'AutorizacionMetodoFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'AutorizacionMetodoFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'AutorizacionMetodoEstado' => $this->booleano(),
        ];
    }

    /** fin >= inicio y UNIQUE (trabajador, metodo): una sola autorizacion por trabajador y metodo (se renueva editando su vigencia). */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('AutorizacionMetodoFechaInicio');
            $fin = $this->fechaEfectiva('AutorizacionMetodoFechaFin');

            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('AutorizacionMetodoFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $existe = AutorizacionMetodo::query()
                ->where('TrabajadorId', $this->valorEfectivo('TrabajadorId'))
                ->where('MetodoMarcacionId', $this->valorEfectivo('MetodoMarcacionId'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('AutorizacionMetodoId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('MetodoMarcacionId', 'El trabajador ya tiene una autorización para ese método: edita su vigencia en lugar de crear otra.');
            }
        });
    }
}
