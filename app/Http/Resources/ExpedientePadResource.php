<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpedientePadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ExpedientePadId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'tipo_falta_disciplinaria_id' => $this->TipoFaltaDisciplinariaId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'numero' => $this->ExpedientePadNumero,
            'fecha_inicio' => optional($this->ExpedientePadFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->ExpedientePadFechaFin)->format('Y-m-d'),
            'descripcion' => $this->ExpedientePadDescripcion,
            'sancion' => $this->ExpedientePadSancion,
            'estado' => $this->ExpedientePadEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ExpedientePadEstado, ['ANULADO', 'ANULADA'], true),
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
            'tipo_falta' => $this->whenLoaded('tipoFalta', fn () => $this->tipoFalta ? [
                'id' => $this->tipoFalta->TipoFaltaDisciplinariaId,
                'codigo' => $this->tipoFalta->TipoFaltaDisciplinariaCodigo,
                'nombre' => $this->tipoFalta->TipoFaltaDisciplinariaNombre,
                'gravedad' => $this->tipoFalta->TipoFaltaDisciplinariaGravedad,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
