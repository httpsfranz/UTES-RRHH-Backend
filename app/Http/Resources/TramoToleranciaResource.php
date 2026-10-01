<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TramoToleranciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TramoToleranciaId,
            'tabla_tolerancia_id' => $this->TablaToleranciaId,
            'tipo' => $this->TramoToleranciaTipo,
            'minutos_desde' => $this->TramoToleranciaMinutosDesde,
            'minutos_hasta' => $this->TramoToleranciaMinutosHasta,
            'factor_descuento' => $this->TramoToleranciaFactorDescuento,
            'minutos_descuento' => $this->TramoToleranciaMinutosDescuento,
            'es_inasistencia' => (bool) $this->TramoToleranciaEsInasistencia,
            'descripcion' => $this->TramoToleranciaDescripcion,
            'tabla_tolerancia' => $this->whenLoaded('tablaTolerancia', fn () => [
                'id' => $this->tablaTolerancia->TablaToleranciaId,
                'codigo' => $this->tablaTolerancia->TablaToleranciaCodigo,
                'nombre' => $this->tablaTolerancia->TablaToleranciaNombre,
            ]),
        ];
    }
}
