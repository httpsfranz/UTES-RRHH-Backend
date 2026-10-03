<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioAmbitoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->UsuarioAmbitoId,
            'usuario_id' => $this->UsuarioId,
            'microred_id' => $this->MicroredId,
            'eess_id' => $this->EessId,
            'activo' => (bool) $this->UsuarioAmbitoEstado,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
            'microred' => $this->whenLoaded('microred', fn () => $this->microred ? [
                'id' => $this->microred->MicroredId,
                'codigo' => $this->microred->MicroredCodigo,
                'nombre' => $this->microred->MicroredNombre,
            ] : null),
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
            'alcance' => $this->EessId ? 'EESS' : ($this->MicroredId ? 'MICRORED' : 'RED'),
        ];
    }
}
