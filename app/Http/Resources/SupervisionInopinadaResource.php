<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisionInopinadaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->SupervisionInopinadaId,
            'eess_id' => $this->EessId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'usuario_id' => $this->UsuarioId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha_hora' => optional($this->SupervisionInopinadaFechaHora)->format('Y-m-d H:i:s'),
            'resultado' => $this->SupervisionInopinadaResultado,
            'observacion' => $this->SupervisionInopinadaObservacion,
            'estado' => $this->SupervisionInopinadaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->SupervisionInopinadaEstado, ['ANULADO', 'ANULADA'], true),
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
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
