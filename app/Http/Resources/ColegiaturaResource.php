<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColegiaturaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ColegiaturaId,
            'trabajador_id' => $this->TrabajadorId,
            'colegiatura_tipo_id' => $this->ColegiaturaTipoId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'numero' => $this->ColegiaturaNumero,
            'fecha_colegiatura' => optional($this->ColegiaturaFechaColegiatura)->format('Y-m-d'),
            'fecha_habilitacion' => optional($this->ColegiaturaFechaHabilitacion)->format('Y-m-d'),
            'fecha_vencimiento' => optional($this->ColegiaturaFechaVencimiento)->format('Y-m-d'),
            'es_habilitado' => (bool) $this->ColegiaturaEsHabilitado,
            'es_principal' => (bool) $this->ColegiaturaEsPrincipal,
            'observacion' => $this->ColegiaturaObservacion,
            // Derivados (RIT, obligacion 37: colegiatura y habilitacion vigentes).
            'vencida' => $this->estaVencida(),
            'vigente' => $this->estaVigente(),
            'activo' => (bool) $this->ColegiaturaEstado,
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
            'tipo' => $this->whenLoaded('tipo', fn () => [
                'id' => $this->tipo->ColegiaturaTipoId,
                'codigo' => $this->tipo->ColegiaturaTipoCodigo,
                'nombre' => $this->tipo->ColegiaturaTipoNombre,
            ]),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
