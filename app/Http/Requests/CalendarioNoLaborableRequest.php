<?php

namespace App\Http\Requests;

use App\Models\Organizacion\Microred;
use App\Models\Soporte\CalendarioNoLaborable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarioNoLaborableRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            // NULL = feriado de alcance nacional / toda la Red.
            'MicroredId' => ['nullable', 'integer', $this->existeActivo(Microred::class, 'MicroredEstado', 'MicroredId')],
            'CalendarioNoLaborableFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'CalendarioNoLaborableTipo' => [
                $this->obligatorio(),
                Rule::in(['FERIADO', 'DIA_NO_LABORABLE', 'ASUETO', 'DUELO']),
            ],
            'CalendarioNoLaborableDescripcion' => $this->texto(250),
            'CalendarioNoLaborableCompensable' => $this->booleano(),
            'CalendarioNoLaborableNormaSustento' => $this->texto(200),
        ];
    }

    public function messages(): array
    {
        return [
            'CalendarioNoLaborableTipo.in' => 'El tipo debe ser FERIADO, DIA_NO_LABORABLE, ASUETO o DUELO.',
        ];
    }

    /**
     * UNIQUE (Fecha, MicroredId) es compuesto, y SQL Server trata NULL como un valor mas:
     * dos filas con la misma fecha y MicroredId NULL (toda la Red) tambien chocan.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $fecha = $this->valorEfectivo('CalendarioNoLaborableFecha');
            $fecha = $fecha instanceof \DateTimeInterface ? $fecha->format('Y-m-d') : $fecha;
            $microredId = $this->valorEfectivo('MicroredId');

            $existe = CalendarioNoLaborable::query()
                ->whereDate('CalendarioNoLaborableFecha', $fecha)
                ->when(
                    $microredId === null,
                    fn ($q) => $q->whereNull('MicroredId'),
                    fn ($q) => $q->where('MicroredId', $microredId),
                )
                ->when($this->registroId(), fn ($q, $id) => $q->where('CalendarioNoLaborableId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add(
                    'CalendarioNoLaborableFecha',
                    $microredId === null
                        ? 'Ya existe un día no laborable en esa fecha para toda la Red.'
                        : 'Ya existe un día no laborable en esa fecha para esa microred.',
                );
            }
        });
    }
}
