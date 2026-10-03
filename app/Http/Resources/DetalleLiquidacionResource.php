<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetalleLiquidacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->DetalleLiquidacionId,
            'liquidacion_descuento_id' => $this->LiquidacionDescuentoId,
            'concepto_descuento_id' => $this->ConceptoDescuentoId,
            'cantidad' => $this->DetalleLiquidacionCantidad,
            'importe' => $this->DetalleLiquidacionImporte,
            'observacion' => $this->DetalleLiquidacionObservacion,
            'liquidacion' => $this->whenLoaded('liquidacion', fn () => $this->liquidacion ? [
                'id' => $this->liquidacion->LiquidacionDescuentoId,
                'estado' => $this->liquidacion->LiquidacionDescuentoEstado,
                'consolidado_asistencia_id' => $this->liquidacion->ConsolidadoAsistenciaId,
            ] : null),
            'concepto' => $this->whenLoaded('concepto', fn () => $this->concepto ? [
                'id' => $this->concepto->ConceptoDescuentoId,
                'codigo' => $this->concepto->ConceptoDescuentoCodigo,
                'nombre' => $this->concepto->ConceptoDescuentoNombre,
            ] : null),
        ];
    }
}
