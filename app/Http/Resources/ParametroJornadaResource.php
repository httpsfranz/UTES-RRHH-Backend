<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParametroJornadaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ParametroJornadaId,
            'tipo_jornada_id' => $this->TipoJornadaId,
            'vigencia_desde' => optional($this->ParametroJornadaVigenciaDesde)->format('Y-m-d'),
            'vigencia_hasta' => optional($this->ParametroJornadaVigenciaHasta)->format('Y-m-d'),
            'horas_diarias' => $this->ParametroJornadaHorasDiarias,
            'horas_semanales' => $this->ParametroJornadaHorasSemanales,
            'horas_mensuales' => $this->ParametroJornadaHorasMensuales,
            'activo' => (bool) $this->ParametroJornadaEstado,
            'tipo_jornada' => $this->whenLoaded('tipoJornada', fn () => [
                'id' => $this->tipoJornada->TipoJornadaId,
                'nombre' => $this->tipoJornada->TipoJornadaNombre,
            ]),
        ];
    }
}
