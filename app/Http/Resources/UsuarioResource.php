<?php

namespace App\Http\Resources;

use App\Support\HoraLocal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Nunca expone UsuarioPasswordHash (ni existe un campo "password" en la respuesta).
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->UsuarioId,
            'trabajador_id' => $this->TrabajadorId,
            'nombre' => $this->UsuarioNombre,
            'correo' => $this->UsuarioCorreo,
            'fecha_creacion' => optional($this->UsuarioFechaCreacion)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->UsuarioEstado,
            // Estado de la cuenta: ACTIVO, INACTIVO (desactivada) o BLOQUEADO (a mano o por intentos fallidos).
            'estado_cuenta' => ! $this->UsuarioEstado ? 'INACTIVO' : ($this->UsuarioBloqueado && ($this->UsuarioBloqueadoHasta === null || $this->UsuarioBloqueadoHasta > HoraLocal::ahora()) ? 'BLOQUEADO' : 'ACTIVO'),
            'bloqueado_hasta' => optional($this->UsuarioBloqueadoHasta)->format('Y-m-d H:i:s'),
            'motivo_bloqueo' => $this->UsuarioMotivoBloqueo,
            'intentos_fallidos' => (int) $this->UsuarioIntentosFallidos,
            'debe_cambiar_clave' => (bool) $this->UsuarioDebeCambiarPassword,
            'ultimo_acceso' => optional($this->UsuarioUltimoAcceso)->format('Y-m-d H:i:s'),
            'roles' => $this->whenLoaded('usuarioRoles', fn () => $this->usuarioRoles->map(fn ($ur) => [
                'id' => $ur->RolId,
                'codigo' => $ur->rol?->RolCodigo,
                'nombre' => $ur->rol?->RolNombre,
            ])->values()->all()),
            // Ambito de datos: sin filas = toda la Red; microred = todos sus establecimientos; establecimiento = solo ese.
            'ambitos' => $this->whenLoaded('ambitos', fn () => $this->ambitos->map(fn ($a) => [
                'alcance' => $a->EessId ? 'EESS' : ($a->MicroredId ? 'MICRORED' : 'RED'),
                'nombre' => $a->eess?->EessNombre ?? $a->microred?->MicroredNombre ?? 'Toda la Red',
            ])->values()->all()),
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
        ];
    }
}
