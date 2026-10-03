<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Validation\Validator;

/**
 * Consolidado mensual de asistencia de un vinculo (RIT, Art. 16: la asistencia se consolida hasta el dia 05 de cada mes).
 * Normalmente lo genera el proceso (POST /consolidados-asistencia/generar) a partir de la asistencia diaria; esta API
 * permite ademas el registro y la correccion manual mientras el periodo no este cerrado.
 */
class ConsolidadoAsistenciaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** CERRADO no se envia: lo pone el cierre del periodo de asistencia. */
    public const ESTADOS_EDITABLES = ['GENERADO', 'OBSERVADO', 'CONFORME'];

    public function rules(): array
    {
        return [
            'PeriodoAsistenciaId' => [$this->obligatorio(), 'integer', $this->existe(PeriodoAsistencia::class)],
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'ConsolidadoAsistenciaDiasTrabajados' => ['sometimes', 'numeric', 'between:0,31', 'regex:/^\d+(\.\d{1,2})?$/'],
            'ConsolidadoAsistenciaDiasFalta' => ['sometimes', 'numeric', 'between:0,31', 'regex:/^\d+(\.\d{1,2})?$/'],
            'ConsolidadoAsistenciaDiasFaltaJustificada' => ['sometimes', 'numeric', 'between:0,31', 'regex:/^\d+(\.\d{1,2})?$/'],
            'ConsolidadoAsistenciaMinutosTardanza' => ['sometimes', 'integer', 'between:0,60000'],
            'ConsolidadoAsistenciaMinutosExtra' => ['sometimes', 'integer', 'between:0,60000'],
            'ConsolidadoAsistenciaEstado' => ['sometimes', 'in:'.implode(',', self::ESTADOS_EDITABLES)],
        ];
    }

    public function messages(): array
    {
        return [
            '*.regex' => 'Los días admiten hasta 2 decimales (por ejemplo 21.5).',
            '*.between' => 'El valor está fuera del rango permitido.',
            'ConsolidadoAsistenciaEstado.in' => 'El estado debe ser GENERADO, OBSERVADO o CONFORME (CERRADO lo pone el cierre del período).',
        ];
    }

    /**
     * - El periodo no puede estar CERRADO (ni para crear, ni para modificar, ni para mover el consolidado a otro periodo).
     * - Un solo consolidado por periodo y vinculo; el vinculo debe haber estado vigente en alguna fecha del periodo.
     * - Los dias no superan los dias del periodo.
     * - Un consolidado CONFORME o CERRADO ya no se modifica.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $actual = $this->registro()?->ConsolidadoAsistenciaEstado;
            if (in_array($actual, ['CONFORME', 'CERRADO'], true) && ! ($actual === 'CONFORME' && $this->soloCambiaEstado())) {
                $validator->errors()->add('ConsolidadoAsistenciaEstado', 'El consolidado está '.strtolower($actual === 'CERRADO' ? 'cerrado' : 'conforme').' y ya no se puede modificar.');

                return;
            }

            // Con una liquidacion de descuentos vigente, el consolidado que la origino ya no se toca.
            $registro = $this->registro();
            if ($registro && $registro->liquidacion()->where('LiquidacionDescuentoEstado', '<>', 'ANULADO')->exists()) {
                $validator->errors()->add('ConsolidadoAsistenciaEstado', 'El consolidado tiene una liquidación de descuentos vigente: anúlala antes de modificarlo.');

                return;
            }

            $periodo = PeriodoAsistencia::query()->find($this->valorEfectivo('PeriodoAsistenciaId'));
            if (! $periodo) {
                return;
            }
            if ($periodo->PeriodoAsistenciaEstado === 'CERRADO') {
                $validator->errors()->add('PeriodoAsistenciaId', 'El período de asistencia está cerrado: no se registran ni modifican consolidados.');

                return;
            }

            $inicio = $periodo->PeriodoAsistenciaFechaInicio->toDateString();
            $fin = $periodo->PeriodoAsistenciaFechaFin->toDateString();
            $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
            if ($vinculo && ($vinculo->VinculoLaboralFechaInicio->toDateString() > $fin || ($vinculo->VinculoLaboralFechaFin && $vinculo->VinculoLaboralFechaFin->toDateString() < $inicio))) {
                $validator->errors()->add('VinculoLaboralId', 'El vínculo laboral no estuvo vigente en ese período.');
            }

            $existe = ConsolidadoAsistencia::query()
                ->where('PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('VinculoLaboralId', 'Ese trabajador ya tiene su consolidado de ese período.');
            }

            $diasDelPeriodo = (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1;
            foreach (['ConsolidadoAsistenciaDiasTrabajados', 'ConsolidadoAsistenciaDiasFalta', 'ConsolidadoAsistenciaDiasFaltaJustificada'] as $campo) {
                if ((float) $this->valorEfectivo($campo) > $diasDelPeriodo) {
                    $validator->errors()->add($campo, "No puede superar los {$diasDelPeriodo} días del período.");
                }
            }
        });
    }

    /** Un CONFORME solo puede volver a OBSERVADO/GENERADO cambiando unicamente el estado. */
    private function soloCambiaEstado(): bool
    {
        return count($this->all()) === 1 && $this->exists('ConsolidadoAsistenciaEstado');
    }
}
