<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InformeGuardiaComunitariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->InformeGuardiaComunitariaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'turno_programado_id' => $this->TurnoProgramadoId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha' => optional($this->InformeGuardiaComunitariaFecha)->format('Y-m-d'),
            'hora_inicio' => $this->InformeGuardiaComunitariaHoraInicio === null ? null : substr((string) $this->InformeGuardiaComunitariaHoraInicio, 0, 5),
            'hora_fin' => $this->InformeGuardiaComunitariaHoraFin === null ? null : substr((string) $this->InformeGuardiaComunitariaHoraFin, 0, 5),
            'descripcion' => $this->InformeGuardiaComunitariaDescripcion,
            'estado' => $this->InformeGuardiaComunitariaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->InformeGuardiaComunitariaEstado, ['ANULADO', 'ANULADA'], true),
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
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
