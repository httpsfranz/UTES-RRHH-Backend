<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DispositivoMarcacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'DispositivoMarcacionId' => $this->DispositivoMarcacionId,
            'EessId' => $this->EessId,
            'DispositivoMarcacionCodigo' => $this->DispositivoMarcacionCodigo,
            'DispositivoMarcacionNombre' => $this->DispositivoMarcacionNombre,
            'DispositivoMarcacionTipo' => $this->DispositivoMarcacionTipo,
            'DispositivoMarcacionUbicacion' => $this->DispositivoMarcacionUbicacion,
            'DispositivoMarcacionIp' => $this->DispositivoMarcacionIp,
            'DispositivoMarcacionEstado' => $this->DispositivoMarcacionEstado,
        ];
    }
}