<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResponsableEessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ResponsableEessId,
            'eess_id' => $this->EessId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'tipo_responsabilidad_id' => $this->TipoResponsabilidadId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha_inicio' => optional($this->ResponsableEessFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->ResponsableEessFechaFin)->format('Y-m-d'),
            'documento_numero' => $this->ResponsableEessDocumentoNumero,
            'observacion' => $this->ResponsableEessObservacion,
            'fecha_registro' => optional($this->ResponsableEessFechaRegistro)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->ResponsableEessEstado,
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
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
            'tipo_responsabilidad' => $this->whenLoaded('tipoResponsabilidad', fn () => $this->tipoResponsabilidad ? [
                'id' => $this->tipoResponsabilidad->TipoResponsabilidadId,
                'codigo' => $this->tipoResponsabilidad->TipoResponsabilidadCodigo,
                'nombre' => $this->tipoResponsabilidad->TipoResponsabilidadNombre,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
