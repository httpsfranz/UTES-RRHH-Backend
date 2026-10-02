<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentimientoBiometricoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ConsentimientoBiometricoId,
            'trabajador_id' => $this->TrabajadorId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha' => optional($this->ConsentimientoBiometricoFecha)->format('Y-m-d H:i:s'),
            'aceptado' => (bool) $this->ConsentimientoBiometricoAceptado,
            'version' => $this->ConsentimientoBiometricoVersion,
            // true = es el ultimo evento del trabajador, es decir, el consentimiento que rige hoy (lo marca el controlador).
            'vigente' => (bool) ($this->es_vigente ?? false),
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
