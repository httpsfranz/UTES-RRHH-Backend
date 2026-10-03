<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AjusteMarcacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->AjusteMarcacionId,
            'marcacion_id' => $this->MarcacionId,
            'usuario_id' => $this->UsuarioId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha_hora' => optional($this->AjusteMarcacionFechaHora)->format('Y-m-d H:i:s'),
            'fecha_hora_anterior' => optional($this->AjusteMarcacionFechaHoraAnterior)->format('Y-m-d H:i:s'),
            'fecha_hora_nueva' => optional($this->AjusteMarcacionFechaHoraNueva)->format('Y-m-d H:i:s'),
            'motivo' => $this->AjusteMarcacionMotivo,
            'estado' => $this->AjusteMarcacionEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->AjusteMarcacionEstado, ['ANULADO', 'ANULADA'], true),
            'trabajador' => $this->whenLoaded('marcacion', fn () => $this->marcacion?->vinculoLaboral?->relationLoaded('trabajador') && $this->marcacion?->vinculoLaboral->trabajador ? [
                'id' => $this->marcacion?->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->marcacion?->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->marcacion?->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('marcacion', fn () => $this->marcacion?->vinculoLaboral ? [
                'id' => $this->marcacion?->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->marcacion?->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->marcacion?->vinculoLaboral->EessId,
            ] : null),
            'marcacion' => $this->whenLoaded('marcacion', fn () => $this->marcacion ? [
                'id' => $this->marcacion->MarcacionId,
                'fecha_hora' => optional($this->marcacion->MarcacionFechaHora)->format('Y-m-d H:i:s'),
                'tipo' => $this->marcacion->MarcacionTipo,
                'es_valida' => (bool) $this->marcacion->MarcacionEsValida,
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
