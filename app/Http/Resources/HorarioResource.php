<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HorarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->HorarioId,
            'tipo_jornada_id' => $this->TipoJornadaId,
            'eess_id' => $this->EessId,
            'codigo' => $this->HorarioCodigo,
            'nombre' => $this->HorarioNombre,
            'descripcion' => $this->HorarioDescripcion,
            'es_rotativo' => (bool) $this->HorarioEsRotativo,
            'activo' => (bool) $this->HorarioEstado,
            'tipo_jornada' => $this->whenLoaded('tipoJornada', fn () => [
                'id' => $this->tipoJornada->TipoJornadaId,
                'nombre' => $this->tipoJornada->TipoJornadaNombre,
            ]),
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
        ];
    }
}
