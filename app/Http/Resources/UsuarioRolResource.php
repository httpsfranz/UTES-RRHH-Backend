<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UsuarioRolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->UsuarioRolId,
            'usuario_id' => $this->UsuarioId,
            'rol_id' => $this->RolId,
            'fecha_inicio' => optional($this->UsuarioRolFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->UsuarioRolFechaFin)->format('Y-m-d'),
            'activo' => (bool) $this->UsuarioRolEstado,
            // Trazabilidad: quien asigno el rol y cuando; quien lo revoco y cuando.
            'asignado_por' => $this->UsuarioRolAsignadoPor ? DB::table('Seguridad.Usuario')->where('UsuarioId', $this->UsuarioRolAsignadoPor)->value('UsuarioNombre') : null,
            'fecha_asignacion' => $this->UsuarioRolFechaAsignacion ? substr((string) $this->UsuarioRolFechaAsignacion, 0, 19) : null,
            'revocado_por' => $this->UsuarioRolRevocadoPor ? DB::table('Seguridad.Usuario')->where('UsuarioId', $this->UsuarioRolRevocadoPor)->value('UsuarioNombre') : null,
            'fecha_revocacion' => $this->UsuarioRolFechaRevocacion ? substr((string) $this->UsuarioRolFechaRevocacion, 0, 19) : null,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
                'trabajador' => $this->usuario->relationLoaded('trabajador') && $this->usuario->trabajador ? trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->usuario->trabajador->TrabajadorNombreCompleto))) : null,
            ] : null),
            'rol' => $this->whenLoaded('rol', fn () => $this->rol ? [
                'id' => $this->rol->RolId,
                'codigo' => $this->rol->RolCodigo,
                'nombre' => $this->rol->RolNombre,
            ] : null),
        ];
    }
}
