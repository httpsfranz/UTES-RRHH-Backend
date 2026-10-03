<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarcacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->MarcacionId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'metodo_marcacion_id' => $this->MetodoMarcacionId,
            'dispositivo_marcacion_id' => $this->DispositivoMarcacionId,
            'plantilla_biometrica_id' => $this->PlantillaBiometricaId,
            'carga_asistencia_manual_id' => $this->CargaAsistenciaManualId,
            'fecha_hora' => optional($this->MarcacionFechaHora)->format('Y-m-d H:i:s'),
            'tipo' => $this->MarcacionTipo,
            'geolocalizacion' => $this->MarcacionGeolocalizacion,
            'observacion' => $this->MarcacionObservacion,
            'origen' => $this->MarcacionOrigen,
            'es_valida' => (bool) $this->MarcacionEsValida,
            'trabajador' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral?->relationLoaded('trabajador') && $this->vinculoLaboral->trabajador ? [
                'id' => $this->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral ? [
                'id' => $this->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->vinculoLaboral->EessId,
            ] : null),
            'metodo' => $this->whenLoaded('metodo', fn () => $this->metodo ? [
                'id' => $this->metodo->MetodoMarcacionId,
                'codigo' => $this->metodo->MetodoMarcacionCodigo,
                'nombre' => $this->metodo->MetodoMarcacionNombre,
            ] : null),
            'dispositivo' => $this->whenLoaded('dispositivo', fn () => $this->dispositivo ? [
                'id' => $this->dispositivo->DispositivoMarcacionId,
                'codigo' => $this->dispositivo->DispositivoMarcacionCodigo,
                'nombre' => $this->dispositivo->DispositivoMarcacionNombre,
            ] : null),
            'activo' => (bool) $this->MarcacionEsValida,
        ];
    }
}
