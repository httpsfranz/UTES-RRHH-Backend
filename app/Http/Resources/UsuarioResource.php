<?php

namespace App\Http\Resources;

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
