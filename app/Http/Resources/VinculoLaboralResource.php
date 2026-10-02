<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VinculoLaboralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hoy = now()->startOfDay();
        $vigente = $this->VinculoLaboralEstado
            && $this->VinculoLaboralFechaInicio->lte($hoy)
            && ($this->VinculoLaboralFechaFin === null || $this->VinculoLaboralFechaFin->gte($hoy));

        return [
            'id' => $this->VinculoLaboralId,
            'trabajador_id' => $this->TrabajadorId,
            'eess_id' => $this->EessId,
            'regimen_laboral_id' => $this->RegimenLaboralId,
            'condicion_laboral_id' => $this->CondicionLaboralId,
            'cargo_id' => $this->CargoId,
            'codigo' => $this->VinculoLaboralCodigo,
            'codigo_airhsp' => $this->VinculoLaboralCodigoAirhsp,
            'numero_plaza' => $this->VinculoLaboralNumeroPlaza,
            'fecha_inicio' => optional($this->VinculoLaboralFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->VinculoLaboralFechaFin)->format('Y-m-d'),
            'motivo_cese' => $this->VinculoLaboralMotivoCese,
            'vigente' => $vigente,
            'activo' => (bool) $this->VinculoLaboralEstado,
            'trabajador' => $this->whenLoaded('trabajador', fn () => [
                'id' => $this->trabajador->TrabajadorId,
                'numero_documento' => $this->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->trabajador->TrabajadorNombreCompleto))),
            ]),
            'eess' => $this->whenLoaded('eess', fn () => [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ]),
            'regimen' => $this->whenLoaded('regimenLaboral', fn () => [
                'id' => $this->regimenLaboral->RegimenLaboralId,
                'nombre' => $this->regimenLaboral->RegimenLaboralNombre,
            ]),
            'condicion' => $this->whenLoaded('condicionLaboral', fn () => [
                'id' => $this->condicionLaboral->CondicionLaboralId,
                'nombre' => $this->condicionLaboral->CondicionLaboralNombre,
                'requiere_airhsp' => (bool) $this->condicionLaboral->CondicionLaboralRequiereAirhsp,
            ]),
            'cargo' => $this->whenLoaded('cargo', fn () => [
                'id' => $this->cargo->CargoId,
                'nombre' => $this->cargo->CargoNombre,
            ]),
        ];
    }
}
