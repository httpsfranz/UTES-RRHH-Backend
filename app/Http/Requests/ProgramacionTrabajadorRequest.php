<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\ProgramacionPeriodo;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Services\ProgramacionPeriodoService;
use Illuminate\Validation\Validator;

/**
 * Programacion de un trabajador dentro del periodo de su establecimiento. Se arma mientras la programacion es un
 * borrador (RIT, Art. 16: remitida, queda prohibida cualquier modificacion). Las horas programadas las calcula el
 * sistema a partir de sus turnos y el estado lo hereda de la programacion del periodo: ninguno se envia.
 */
class ProgramacionTrabajadorRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'ProgramacionPeriodoId' => [$this->obligatorio(), 'integer', $this->existe(ProgramacionPeriodo::class)],
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'ProgramacionTrabajadorObservacion' => $this->texto(500),
        ];
    }

    /**
     * - La programacion del periodo sigue en borrador (tambien la de origen, si se mueve a otra).
     * - El trabajador pertenece al establecimiento de la programacion y su vinculo estuvo vigente en algun dia del periodo.
     * - Un trabajador una sola vez por periodo; con turnos cargados no cambia de trabajador ni de periodo.
     * - Ni las horas ni el estado se envian.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            foreach (['ProgramacionTrabajadorEstado', 'ProgramacionTrabajadorHorasProgramadas'] as $columna) {
                if ($this->exists($columna)) {
                    $validator->errors()->add($columna, 'Este dato lo calcula el sistema: el estado sigue al de la programación y las horas, a los turnos.');
                }
            }
            $registro = $this->registro();
            if ($registro && ($motivo = ProgramacionPeriodoService::motivoDeBloqueo($registro->periodo))) {
                $validator->errors()->add('ProgramacionPeriodoId', $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $periodo = ProgramacionPeriodo::query()->find($this->valorEfectivo('ProgramacionPeriodoId'));
            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            if ($motivo = ProgramacionPeriodoService::motivoDeBloqueo($periodo)) {
                $validator->errors()->add('ProgramacionPeriodoId', $motivo);

                return;
            }

            if ($vinculo->EessId !== $periodo->EessId) {
                $validator->errors()->add('VinculoLaboralId', 'El trabajador pertenece a otro establecimiento: solo se programa al personal del establecimiento de la programación.');
            }
            $inicio = $periodo->ProgramacionPeriodoFechaInicio->toDateString();
            $fin = $periodo->ProgramacionPeriodoFechaFin->toDateString();
            if ($vinculo->VinculoLaboralFechaInicio->toDateString() > $fin || ($vinculo->VinculoLaboralFechaFin && $vinculo->VinculoLaboralFechaFin->toDateString() < $inicio)) {
                $validator->errors()->add('VinculoLaboralId', 'El vínculo laboral no estuvo vigente en ningún día de este período.');
            }

            $existe = ProgramacionTrabajador::query()
                ->where('ProgramacionPeriodoId', $periodo->ProgramacionPeriodoId)
                ->where('VinculoLaboralId', $vinculo->VinculoLaboralId)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('VinculoLaboralId', 'Ese trabajador ya está en la programación de este período.');
            }

            if ($registro && $registro->turnos()->exists()
                && ((int) $this->valorEfectivo('ProgramacionPeriodoId') !== $registro->ProgramacionPeriodoId || (int) $this->valorEfectivo('VinculoLaboralId') !== $registro->VinculoLaboralId)) {
                $validator->errors()->add('VinculoLaboralId', 'Ya tiene turnos programados: retíralos antes de cambiar de trabajador o de programación.');
            }
        });
    }
}
