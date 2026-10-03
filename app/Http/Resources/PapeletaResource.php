<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PapeletaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->PapeletaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'tipo_papeleta_id' => $this->TipoPapeletaId,
            'motivo_papeleta_id' => $this->MotivoPapeletaId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'usuario_autorizacion_id' => $this->UsuarioAutorizacionId,
            'numero' => $this->PapeletaNumero,
            'fecha' => optional($this->PapeletaFecha)->format('Y-m-d'),
            'hora_salida' => $this->PapeletaHoraSalida === null ? null : substr((string) $this->PapeletaHoraSalida, 0, 5),
            'hora_retorno' => $this->PapeletaHoraRetorno === null ? null : substr((string) $this->PapeletaHoraRetorno, 0, 5),
            'es_dia_completo' => (bool) $this->PapeletaEsDiaCompleto,
            'minutos_utilizados' => $this->PapeletaMinutosUtilizados,
            'motivo' => $this->PapeletaMotivo,
            'observacion' => $this->PapeletaObservacion,
            'fecha_registro' => optional($this->PapeletaFechaRegistro)->format('Y-m-d H:i:s'),
            'fecha_resolucion' => optional($this->PapeletaFechaResolucion)->format('Y-m-d H:i:s'),
            'estado' => $this->PapeletaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->PapeletaEstado, ['ANULADO', 'ANULADA'], true),
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
            'tipo' => $this->whenLoaded('tipo', fn () => $this->tipo ? [
                'id' => $this->tipo->TipoPapeletaId,
                'codigo' => $this->tipo->TipoPapeletaCodigo,
                'nombre' => $this->tipo->TipoPapeletaNombre,
                'requiere_sustento' => (bool) $this->tipo->TipoPapeletaRequiereSustento,
            ] : null),
            'motivo_papeleta' => $this->whenLoaded('motivo', fn () => $this->motivo ? [
                'id' => $this->motivo->MotivoPapeletaId,
                'codigo' => $this->motivo->MotivoPapeletaCodigo,
                'nombre' => $this->motivo->MotivoPapeletaNombre,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
            'usuario_autorizacion' => $this->whenLoaded('usuarioAutorizacion', fn () => $this->usuarioAutorizacion ? [
                'id' => $this->usuarioAutorizacion->UsuarioId,
                'nombre' => $this->usuarioAutorizacion->UsuarioNombre,
            ] : null),
        ];
    }
}
