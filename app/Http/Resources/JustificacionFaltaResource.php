<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JustificacionFaltaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->JustificacionFaltaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'concepto_justificacion_id' => $this->ConceptoJustificacionId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'usuario_resolucion_id' => $this->UsuarioResolucionId,
            'fecha_inicio' => optional($this->JustificacionFaltaFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->JustificacionFaltaFechaFin)->format('Y-m-d'),
            'documento_numero' => $this->JustificacionFaltaDocumentoNumero,
            'observacion' => $this->JustificacionFaltaObservacion,
            'motivo_rechazo' => $this->JustificacionFaltaMotivoRechazo,
            'fecha_registro' => optional($this->JustificacionFaltaFechaRegistro)->format('Y-m-d H:i:s'),
            'fecha_resolucion' => optional($this->JustificacionFaltaFechaResolucion)->format('Y-m-d H:i:s'),
            'estado' => $this->JustificacionFaltaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->JustificacionFaltaEstado, ['ANULADO', 'ANULADA'], true),
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
            'concepto' => $this->whenLoaded('concepto', fn () => $this->concepto ? [
                'id' => $this->concepto->ConceptoJustificacionId,
                'codigo' => $this->concepto->ConceptoJustificacionCodigo,
                'nombre' => $this->concepto->ConceptoJustificacionNombre,
                'requiere_documento' => (bool) $this->concepto->ConceptoJustificacionRequiereDocumento,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
            'usuario_resolucion' => $this->whenLoaded('usuarioResolucion', fn () => $this->usuarioResolucion ? [
                'id' => $this->usuarioResolucion->UsuarioId,
                'nombre' => $this->usuarioResolucion->UsuarioNombre,
            ] : null),
        ];
    }
}
