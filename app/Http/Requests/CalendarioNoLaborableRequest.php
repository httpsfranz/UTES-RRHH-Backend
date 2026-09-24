<?php

namespace App\Http\Requests;

use App\Models\Organizacion\Microred;
use App\Models\Soporte\CalendarioNoLaborable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarioNoLaborableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $esCreacion = $this->isMethod('POST');

        return [
            // NULL = feriado de alcance nacional / toda la Red.
            'MicroredId' => ['nullable', 'integer', Rule::exists(Microred::class, 'MicroredId')],
            'CalendarioNoLaborableFecha' => [$esCreacion ? 'required' : 'sometimes', 'date'],
            'CalendarioNoLaborableTipo'  => [
                $esCreacion ? 'required' : 'sometimes',
                Rule::in(['FERIADO', 'DIA_NO_LABORABLE', 'ASUETO', 'DUELO']),
            ],
            'CalendarioNoLaborableDescripcion'   => ['nullable', 'string', 'max:250'],
            'CalendarioNoLaborableCompensable'   => ['sometimes', 'boolean'],
            'CalendarioNoLaborableNormaSustento' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'MicroredId.exists'          => 'La microred indicada no existe.',
            'CalendarioNoLaborableTipo.in' => 'El tipo debe ser FERIADO, DIA_NO_LABORABLE, ASUETO o DUELO.',
        ];
    }

    // UNIQUE (Fecha, MicroredId) es una restriccion compuesta: Rule::unique de una sola
    // columna no la cubre, y MicroredId puede ser NULL (SQL Server permite varias filas
    // NULL bajo UNIQUE, asi que el duplicado real solo importa cuando MicroredId coincide).
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $fecha = $this->input('CalendarioNoLaborableFecha');

            if (! $fecha) {
                return;
            }

            $microredId = $this->input('MicroredId');
            $id = $this->route('dia')?->CalendarioNoLaborableId;

            $existe = CalendarioNoLaborable::where('CalendarioNoLaborableFecha', $fecha)
                ->where('MicroredId', $microredId)
                ->when($id, fn ($q) => $q->where('CalendarioNoLaborableId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add(
                    'CalendarioNoLaborableFecha',
                    'Ya existe un registro para esa fecha y esa microred (o alcance nacional).'
                );
            }
        });
    }
}
