<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CargaProgramacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CargaProgramacionId,
            'eess_id' => $this->EessId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'tipo_periodo_programacion_id' => $this->TipoPeriodoProgramacionId,
            'programacion_periodo_id' => $this->ProgramacionPeriodoId,
            'codigo' => $this->CargaProgramacionCodigo,
            'anio' => $this->CargaProgramacionAnio,
            'mes' => $this->CargaProgramacionMes,
            'numero' => $this->CargaProgramacionNumero,
            'fecha_documento' => optional($this->CargaProgramacionFechaDocumento)->format('Y-m-d'),
            'documento_numero' => $this->CargaProgramacionDocumentoNumero,
            'motivo' => $this->CargaProgramacionMotivo,
            'observacion' => $this->CargaProgramacionObservacion,
            'fecha_registro' => optional($this->CargaProgramacionFechaRegistro)->format('Y-m-d H:i:s'),
            'estado' => $this->CargaProgramacionEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->CargaProgramacionEstado, ['ANULADO', 'ANULADA'], true),
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
            'tipo_periodo' => $this->whenLoaded('tipoPeriodo', fn () => $this->tipoPeriodo ? [
                'id' => $this->tipoPeriodo->TipoPeriodoProgramacionId,
                'codigo' => $this->tipoPeriodo->TipoPeriodoProgramacionCodigo,
                'nombre' => $this->tipoPeriodo->TipoPeriodoProgramacionNombre,
            ] : null),
        ];
    }
}
