<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Programacion\TipoCambioTurno
 */
class TipoCambioTurnoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->TipoCambioTurnoId,
            'codigo'                 => $this->TipoCambioTurnoCodigo,
            'nombre'                 => $this->TipoCambioTurnoNombre,
            'requiere_reemplazante'  => (bool) $this->TipoCambioTurnoRequiereReemplazante,
            'descripcion'            => $this->TipoCambioTurnoDescripcion,
            'activo'                 => (bool) $this->TipoCambioTurnoEstado,
        ];
    }
}
