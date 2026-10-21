<?php

namespace App\Http\Resources;

use App\Support\RegistroAuditoria;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->AuditoriaId,
            'usuario_id' => $this->UsuarioId,
            'fecha_hora' => optional($this->AuditoriaFechaHora)->format('Y-m-d H:i:s'),
            'esquema' => $this->AuditoriaEsquema,
            'tabla' => $this->AuditoriaTabla,
            'operacion' => $this->AuditoriaOperacion,
            'registro_id' => $this->AuditoriaRegistroId,
            'datos_anteriores' => $this->AuditoriaDatosAnteriores,
            'datos_nuevos' => $this->AuditoriaDatosNuevos,
            'direccion_ip' => $this->AuditoriaDireccionIp,
            // EXITO, FALLO o DENEGADO; modulo del sistema y detalle legible del evento.
            'resultado' => $this->AuditoriaResultado,
            'modulo' => $this->AuditoriaModulo ?? RegistroAuditoria::modulo((string) $this->AuditoriaEsquema),
            'detalle' => $this->AuditoriaDetalle,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? ['id' => $this->usuario->UsuarioId, 'nombre' => $this->usuario->UsuarioNombre] : null),
        ];
    }
}
