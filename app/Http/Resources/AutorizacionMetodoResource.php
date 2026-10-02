<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutorizacionMetodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->AutorizacionMetodoId,
            'trabajador_id' => $this->TrabajadorId,
            'metodo_marcacion_id' => $this->MetodoMarcacionId,
            'fecha_inicio' => optional($this->AutorizacionMetodoFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->AutorizacionMetodoFechaFin)->format('Y-m-d'),
            // Rige hoy: activa, ya iniciada y no vencida.
            'vigente' => $this->estaVigente(),
            'activo' => (bool) $this->AutorizacionMetodoEstado,
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
            'metodo' => $this->whenLoaded('metodo', fn () => [
                'id' => $this->metodo->MetodoMarcacionId,
                'codigo' => $this->metodo->MetodoMarcacionCodigo,
                'nombre' => $this->metodo->MetodoMarcacionNombre,
            ]),
        ];
    }
}
