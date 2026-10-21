<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class SesionAccesoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->SesionAccesoId,
            'usuario_id' => $this->UsuarioId,
            'fecha_inicio' => optional($this->SesionAccesoFechaInicio)->format('Y-m-d H:i:s'),
            'fecha_fin' => optional($this->SesionAccesoFechaFin)->format('Y-m-d H:i:s'),
            'direccion_ip' => $this->SesionAccesoDireccionIp,
            'resultado' => $this->SesionAccesoResultado,
            'ultima_actividad' => optional($this->SesionAccesoUltimaActividad)->format('Y-m-d H:i:s'),
            'expira_en' => optional($this->SesionAccesoExpiraEn)->format('Y-m-d H:i:s'),
            // LOGOUT, EXPIRADA, INACTIVIDAD, REVOCADA o BLOQUEO; abierta = exitosa y sin fin.
            'motivo_cierre' => $this->SesionAccesoMotivoCierre,
            'abierta' => $this->SesionAccesoResultado === 'EXITOSO' && $this->SesionAccesoFechaFin === null,
            'agente' => $this->SesionAccesoAgente,
            'revocada_por' => $this->SesionAccesoRevocadaPor ? DB::table('Seguridad.Usuario')->where('UsuarioId', $this->SesionAccesoRevocadaPor)->value('UsuarioNombre') : null,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
        ];
    }
}
