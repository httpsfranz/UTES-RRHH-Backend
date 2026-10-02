<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Dato personal sensible: NUNCA incluye la referencia binaria; solo si existe y cuanto pesa.
class PlantillaBiometricaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bytes = $this->PlantillaBiometricaReferenciaBytes ?? null;

        return [
            'id' => $this->PlantillaBiometricaId,
            'trabajador_id' => $this->TrabajadorId,
            'tipo' => $this->PlantillaBiometricaTipo,
            'dedo' => $this->PlantillaBiometricaDedo,
            'tiene_referencia' => $bytes !== null && (int) $bytes > 0,
            'referencia_bytes' => $bytes === null ? null : (int) $bytes,
            'fecha_registro' => optional($this->PlantillaBiometricaFechaRegistro)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->PlantillaBiometricaEstado,
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
        ];
    }
}
