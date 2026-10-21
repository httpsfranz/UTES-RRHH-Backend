<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\Microred;
use App\Models\Soporte\CalendarioNoLaborable;
use App\Services\CalendarioNoLaborableService;
use App\Support\PeriodosDeAsistencia;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarioNoLaborableRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

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
            // Si no se escribe, se completa sola segun el tipo (CalendarioNoLaborableService::normaPorDefecto).
            'CalendarioNoLaborableNormaSustento' => $this->texto(200),
            'CalendarioNoLaborableEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'CalendarioNoLaborableTipo.in' => 'El tipo debe ser FERIADO, DIA_NO_LABORABLE, ASUETO o DUELO.',
        ];
    }

    /**
     * - Duplicados: misma fecha y microred (UNIQUE), y tambien un dia de una microred que ya es no laborable para toda la
     *   Red (o al reves): seria el mismo dia registrado dos veces.
     * - Un periodo de asistencia CERRADO ya se consolido: no admite cambios de su calendario.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $fecha = $this->fechaEfectiva('CalendarioNoLaborableFecha');
            $microredId = $this->valorEfectivo('MicroredId');

            $anterior = $this->registro()?->CalendarioNoLaborableFecha?->toDateString();
            foreach (array_unique(array_filter([$fecha, $anterior])) as $f) {
                if (PeriodosDeAsistencia::cerradoEn($f)) {
                    $validator->errors()->add('CalendarioNoLaborableFecha', 'El período de asistencia del '.$this->fechaCorta($f).' está cerrado: su calendario ya no se modifica.');

                    return;
                }
            }

            $choca = CalendarioNoLaborable::query()
                ->whereDate('CalendarioNoLaborableFecha', $fecha)
                ->where('CalendarioNoLaborableEstado', 1)
                ->where(fn ($q) => $microredId === null ? $q->whereRaw('1 = 1') : $q->where('MicroredId', $microredId)->orWhereNull('MicroredId'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('CalendarioNoLaborableId', '!=', $id))
                ->first();

            if ($choca) {
                $validator->errors()->add(
                    'CalendarioNoLaborableFecha',
                    $choca->MicroredId === null
                        ? 'Ese día ya es no laborable para toda la Red.'
                        : ($microredId === null ? 'Ese día ya está registrado para una microred: desactívalo antes de registrarlo para toda la Red.' : 'Ya existe un día no laborable en esa fecha para esa microred.'),
                );
            }
        });
    }

    /** @return array<string,mixed> */
    public function datos(): array
    {
        $datos = $this->validated();
        $tipo = $datos['CalendarioNoLaborableTipo'] ?? $this->registro()?->CalendarioNoLaborableTipo;
        $sinNorma = array_key_exists('CalendarioNoLaborableNormaSustento', $datos) ? blank($datos['CalendarioNoLaborableNormaSustento']) : $this->esCreacion();
        if ($tipo && $sinNorma) {
            $datos['CalendarioNoLaborableNormaSustento'] = CalendarioNoLaborableService::normaPorDefecto((string) $tipo);
        }

        return $datos;
    }
}
