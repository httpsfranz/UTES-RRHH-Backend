<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Compensaciones\LiquidacionDescuento;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Validation\Validator;

/**
 * Generar la liquidacion de descuentos de un consolidado (RIT, Art. 25). Solo se envia el consolidado: las lineas, el
 * importe total, la fecha y el estado los pone el sistema (el estado cambia con aprobar y remitir).
 */
class LiquidacionDescuentoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'ConsolidadoAsistenciaId' => ['required', 'integer', $this->existe(ConsolidadoAsistencia::class)],
        ];
    }

    /** El consolidado esta conforme o cerrado, tiene algo que descontar y aun no tiene una liquidacion vigente. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $consolidado = ConsolidadoAsistencia::query()->find($this->input('ConsolidadoAsistenciaId'));
            if ($motivo = LiquidacionDescuentoService::motivoSiNoSeLiquida($consolidado)) {
                $validator->errors()->add('ConsolidadoAsistenciaId', $motivo);

                return;
            }
            $vigente = LiquidacionDescuento::query()
                ->where('ConsolidadoAsistenciaId', $consolidado->ConsolidadoAsistenciaId)
                ->where('LiquidacionDescuentoEstado', '<>', 'ANULADO')
                ->exists();
            if ($vigente) {
                $validator->errors()->add('ConsolidadoAsistenciaId', 'El consolidado ya tiene su liquidación de descuentos.');
            }
        });
    }
}
