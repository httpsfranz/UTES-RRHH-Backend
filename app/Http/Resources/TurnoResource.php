<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TurnoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TurnoId,
            'tipo_jornada_id' => $this->TipoJornadaId,
            'tabla_tolerancia_id' => $this->TablaToleranciaId,
            'codigo' => $this->TurnoCodigo,
            'nombre' => $this->TurnoNombre,
            // SQL Server devuelve TIME como "07:30:00.0000000": al cliente le llega "07:30".
            'hora_entrada' => substr((string) $this->TurnoHoraEntrada, 0, 5),
            'hora_salida' => substr((string) $this->TurnoHoraSalida, 0, 5),
            'cruza_medianoche' => (bool) $this->TurnoCruzaMedianoche,
            'duracion_minutos' => $this->TurnoDuracionMinutos,
            'tolerancia_entrada_minutos' => $this->TurnoToleranciaEntradaMinutos,
            'tolerancia_salida_minutos' => $this->TurnoToleranciaSalidaMinutos,
            'refrigerio_minutos' => $this->TurnoRefrigerioMinutos,
            'permite_hora_extra' => (bool) $this->TurnoPermiteHoraExtra,
            'es_guardia' => (bool) $this->TurnoEsGuardia,
            'activo' => (bool) $this->TurnoEstado,
            'tipo_jornada' => $this->whenLoaded('tipoJornada', fn () => [
                'id' => $this->tipoJornada->TipoJornadaId,
                'nombre' => $this->tipoJornada->TipoJornadaNombre,
            ]),
            'tabla_tolerancia' => $this->whenLoaded('tablaTolerancia', fn () => $this->tablaTolerancia ? [
                'id' => $this->tablaTolerancia->TablaToleranciaId,
                'nombre' => $this->tablaTolerancia->TablaToleranciaNombre,
            ] : null),
        ];
    }
}
