<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->AuditoriaId,
            'usuario_id'       => $this->UsuarioId,
            'fecha_hora'       => optional($this->AuditoriaFechaHora)->format('Y-m-d H:i:s'),
            'esquema'          => $this->AuditoriaEsquema,
            'tabla'            => $this->AuditoriaTabla,
            'operacion'        => $this->AuditoriaOperacion,
            'registro_id'      => $this->AuditoriaRegistroId,
            'datos_anteriores' => $this->AuditoriaDatosAnteriores,
            'datos_nuevos'     => $this->AuditoriaDatosNuevos,
            'direccion_ip'     => $this->AuditoriaDireccionIp,
        ];
    }
}
