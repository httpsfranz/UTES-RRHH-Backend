<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Asistencia\EstadoAsistencia;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\DetalleConsolidado;
use App\Services\DetalleConsolidadoService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Un dia del consolidado de asistencia: la foto congelada de la asistencia diaria al momento de consolidar. Lo
 * normal es que lo escriba el proceso de generacion del consolidado; esta API permite ademas corregirlo a mano mientras
 * el consolidado no este conforme ni cerrado (despues ya se presento y no se toca).
 */
class DetalleConsolidadoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'ConsolidadoAsistenciaId' => [$this->obligatorio(), 'integer', $this->existe(ConsolidadoAsistencia::class)],
            'AsistenciaDiariaId' => ['nullable', 'integer', $this->existe(AsistenciaDiaria::class)],
            'DetalleConsolidadoFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            // Codigo del estado de asistencia del dia (ASISTIO, TARDANZA, FALTA...).
            'DetalleConsolidadoEstado' => [$this->obligatorio(), 'string', 'max:50', Rule::exists(EstadoAsistencia::class, 'EstadoAsistenciaCodigo')],
            'DetalleConsolidadoMinutosTardanza' => ['sometimes', 'integer', 'between:0,1440'],
            'DetalleConsolidadoMinutosExtra' => ['sometimes', 'integer', 'between:0,1440'],
            'DetalleConsolidadoEsJustificada' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'DetalleConsolidadoFecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'DetalleConsolidadoEstado.exists' => 'El estado debe ser un código de estado de asistencia existente (por ejemplo ASISTIO o FALTA).',
            '*.between' => 'Los minutos de un día van de 0 a 1440.',
        ];
    }

    /**
     * - El consolidado (y el periodo) siguen editables: nada conforme, cerrado ni de un periodo cerrado.
     * - Un solo detalle por consolidado y fecha, y la fecha cae dentro del periodo.
     * - La asistencia diaria enlazada es del mismo trabajador y del mismo dia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $registro = $this->registro();
            if ($registro && ($motivo = DetalleConsolidadoService::motivoDeBloqueo($registro->consolidado))) {
                $validator->errors()->add('ConsolidadoAsistenciaId', $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $consolidado = ConsolidadoAsistencia::query()->with('periodo')->find($this->valorEfectivo('ConsolidadoAsistenciaId'));
            if ($motivo = DetalleConsolidadoService::motivoDeBloqueo($consolidado)) {
                $validator->errors()->add('ConsolidadoAsistenciaId', $motivo);

                return;
            }

            $fecha = $this->fechaEfectiva('DetalleConsolidadoFecha');
            $periodo = $consolidado->periodo;
            if ($fecha < $periodo->PeriodoAsistenciaFechaInicio->toDateString() || $fecha > $periodo->PeriodoAsistenciaFechaFin->toDateString()) {
                $validator->errors()->add('DetalleConsolidadoFecha', 'La fecha está fuera del período del consolidado ('
                    .$this->fechaCorta($periodo->PeriodoAsistenciaFechaInicio->toDateString()).' al '.$this->fechaCorta($periodo->PeriodoAsistenciaFechaFin->toDateString()).').');

                return;
            }

            $existe = DetalleConsolidado::query()
                ->where('ConsolidadoAsistenciaId', $consolidado->ConsolidadoAsistenciaId)
                ->whereDate('DetalleConsolidadoFecha', $fecha)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('DetalleConsolidadoFecha', 'El consolidado ya tiene el detalle de ese día.');
            }

            $asistencia = AsistenciaDiaria::query()->find($this->valorEfectivo('AsistenciaDiariaId'));
            if ($asistencia && ($asistencia->VinculoLaboralId !== $consolidado->VinculoLaboralId || $asistencia->AsistenciaDiariaFecha->toDateString() !== $fecha)) {
                $validator->errors()->add('AsistenciaDiariaId', 'La asistencia diaria debe ser del mismo trabajador y del mismo día del detalle.');
            }
        });
    }
}
