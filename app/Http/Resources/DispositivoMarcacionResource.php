<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DispositivoMarcacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->DispositivoMarcacionId,
            'eess_id' => $this->EessId,
            'codigo' => $this->DispositivoMarcacionCodigo,
            'nombre' => $this->DispositivoMarcacionNombre,
            'tipo' => $this->DispositivoMarcacionTipo,
            'ubicacion' => $this->DispositivoMarcacionUbicacion,
            'ip' => $this->DispositivoMarcacionIp,
            'activo' => (bool) $this->DispositivoMarcacionEstado,
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
        ];
    }
}
