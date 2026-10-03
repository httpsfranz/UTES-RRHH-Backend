<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Compensaciones\ConceptoDescuento;
use App\Models\Compensaciones\DetalleLiquidacion;
use App\Models\Compensaciones\LiquidacionDescuento;
use App\Services\LiquidacionDescuentoService;
use Illuminate\Validation\Validator;

/**
 * Linea de una liquidacion de descuentos: un concepto con su cantidad (dias u horas) y su importe. Se completa con el
 * importe de la planilla mientras la liquidacion esta GENERADA; aprobada o remitida ya no se toca.
 */
class DetalleLiquidacionRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'LiquidacionDescuentoId' => [$this->obligatorio(), 'integer', $this->existe(LiquidacionDescuento::class)],
            'ConceptoDescuentoId' => [$this->obligatorio(), 'integer', $this->existeActivo(ConceptoDescuento::class, 'ConceptoDescuentoEstado', 'ConceptoDescuentoId')],
            'DetalleLiquidacionCantidad' => ['sometimes', 'numeric', 'between:0,99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'DetalleLiquidacionImporte' => ['sometimes', 'numeric', 'between:0,9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'DetalleLiquidacionObservacion' => $this->texto(500),
        ];
    }

    public function messages(): array
    {
        return [
            '*.regex' => 'Admite hasta 2 decimales (por ejemplo 125.50).',
            '*.between' => 'El valor está fuera del rango permitido.',
        ];
    }

    /** La liquidacion (tambien la de origen, si la linea se mueve a otra) sigue GENERADA y el concepto no se repite en ella. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $registro = $this->registro();
            if ($registro && ($motivo = LiquidacionDescuentoService::motivoDeBloqueo($registro->liquidacion))) {
                $validator->errors()->add('LiquidacionDescuentoId', $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $liquidacion = LiquidacionDescuento::query()->find($this->valorEfectivo('LiquidacionDescuentoId'));
            if ($motivo = LiquidacionDescuentoService::motivoDeBloqueo($liquidacion)) {
                $validator->errors()->add('LiquidacionDescuentoId', $motivo);

                return;
            }

            $existe = DetalleLiquidacion::query()
                ->where('LiquidacionDescuentoId', $liquidacion->LiquidacionDescuentoId)
                ->where('ConceptoDescuentoId', $this->valorEfectivo('ConceptoDescuentoId'))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('ConceptoDescuentoId', 'La liquidación ya tiene una línea de ese concepto.');
            }
        });
    }
}
