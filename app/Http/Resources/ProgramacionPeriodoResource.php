<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramacionPeriodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ProgramacionPeriodoId,
            'eess_id' => $this->EessId,
            'tipo_periodo_programacion_id' => $this->TipoPeriodoProgramacionId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'codigo' => $this->ProgramacionPeriodoCodigo,
            'anio' => $this->ProgramacionPeriodoAnio,
            'mes' => $this->ProgramacionPeriodoMes,
            'numero' => $this->ProgramacionPeriodoNumero,
            'fecha_inicio' => optional($this->ProgramacionPeriodoFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->ProgramacionPeriodoFechaFin)->format('Y-m-d'),
            'observacion' => $this->ProgramacionPeriodoObservacion,
            'fecha_registro' => optional($this->ProgramacionPeriodoFechaRegistro)->format('Y-m-d H:i:s'),
            'fecha_publicacion' => optional($this->ProgramacionPeriodoFechaPublicacion)->format('Y-m-d H:i:s'),
            'estado' => $this->ProgramacionPeriodoEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ProgramacionPeriodoEstado, ['ANULADO', 'ANULADA'], true),
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
            'tipo_periodo' => $this->whenLoaded('tipoPeriodo', fn () => $this->tipoPeriodo ? [
                'id' => $this->tipoPeriodo->TipoPeriodoProgramacionId,
                'codigo' => $this->tipoPeriodo->TipoPeriodoProgramacionCodigo,
                'nombre' => $this->tipoPeriodo->TipoPeriodoProgramacionNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
        ];
    }
}
