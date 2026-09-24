<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogIntegracionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->LogIntegracionId,
            'sistema_externo'  => $this->LogIntegracionSistemaExterno,
            'operacion'        => $this->LogIntegracionOperacion,
            'direccion'        => $this->LogIntegracionDireccion,
            'payload_resumen'  => $this->LogIntegracionPayloadResumen,
            'resultado'        => $this->LogIntegracionResultado,
            'mensaje_error'    => $this->LogIntegracionMensajeError,
            'fecha_hora'       => optional($this->LogIntegracionFechaHora)->format('Y-m-d H:i:s'),
            'reintentos'       => $this->LogIntegracionReintentos,
        ];
    }
}
