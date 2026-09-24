<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodoAsistenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->PeriodoAsistenciaId,
            'anio'           => $this->PeriodoAsistenciaAnio,
            'mes'            => $this->PeriodoAsistenciaMes,
            'fecha_inicio'   => optional($this->PeriodoAsistenciaFechaInicio)->format('Y-m-d'),
            'fecha_fin'      => optional($this->PeriodoAsistenciaFechaFin)->format('Y-m-d'),
            'fecha_cierre'   => optional($this->PeriodoAsistenciaFechaCierre)->format('Y-m-d H:i:s'),
            'estado'         => $this->PeriodoAsistenciaEstado,
        ];
    }
}
