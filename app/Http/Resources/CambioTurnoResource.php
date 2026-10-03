<?php

namespace App\Http\Resources;

use App\Models\Programacion\TurnoProgramado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CambioTurnoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CambioTurnoId,
            'tipo_cambio_turno_id' => $this->TipoCambioTurnoId,
            'turno_programado_id' => $this->TurnoProgramadoId,
            'turno_programado_contraparte_id' => $this->TurnoProgramadoContraparteId,
            'turno_id_nuevo' => $this->TurnoIdNuevo,
            'vinculo_laboral_solicitante_id' => $this->VinculoLaboralSolicitanteId,
            'vinculo_laboral_reemplazante_id' => $this->VinculoLaboralReemplazanteId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'usuario_aprobacion_id' => $this->UsuarioAprobacionId,
            'fecha_solicitud' => optional($this->CambioTurnoFechaSolicitud)->format('Y-m-d H:i:s'),
            'fecha_resolucion' => optional($this->CambioTurnoFechaResolucion)->format('Y-m-d H:i:s'),
            'motivo' => $this->CambioTurnoMotivo,
            'observacion' => $this->CambioTurnoObservacion,
            'estado' => $this->CambioTurnoEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => $this->CambioTurnoEstado !== 'ANULADO',
            'tipo' => $this->whenLoaded('tipo', fn () => $this->tipo ? [
                'id' => $this->tipo->TipoCambioTurnoId,
                'codigo' => $this->tipo->TipoCambioTurnoCodigo,
                'nombre' => $this->tipo->TipoCambioTurnoNombre,
                'requiere_reemplazante' => (bool) $this->tipo->TipoCambioTurnoRequiereReemplazante,
            ] : null),
            'turno_programado' => $this->whenLoaded('turnoProgramado', fn () => $this->resumenDeTurno($this->turnoProgramado)),
            'contraparte' => $this->whenLoaded('contraparte', fn () => $this->resumenDeTurno($this->contraparte)),
            'turno_nuevo' => $this->whenLoaded('turnoNuevo', fn () => $this->turnoNuevo ? [
                'id' => $this->turnoNuevo->TurnoId,
                'codigo' => $this->turnoNuevo->TurnoCodigo,
                'nombre' => $this->turnoNuevo->TurnoNombre,
                'hora_entrada' => substr((string) $this->turnoNuevo->TurnoHoraEntrada, 0, 5),
                'hora_salida' => substr((string) $this->turnoNuevo->TurnoHoraSalida, 0, 5),
            ] : null),
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->resumenDePersona($this->solicitante)),
            'reemplazante' => $this->whenLoaded('reemplazante', fn () => $this->resumenDePersona($this->reemplazante)),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
            'usuario_aprobacion' => $this->whenLoaded('usuarioAprobacion', fn () => $this->usuarioAprobacion ? [
                'id' => $this->usuarioAprobacion->UsuarioId,
                'nombre' => $this->usuarioAprobacion->UsuarioNombre,
            ] : null),
        ];
    }

    /** El turno programado afectado (o el de la contraparte) con su fecha, su turno y, si se cargo, su programacion. */
    private function resumenDeTurno(?TurnoProgramado $turno): ?array
    {
        if ($turno === null) {
            return null;
        }

        return [
            'id' => $turno->TurnoProgramadoId,
            'fecha' => optional($turno->TurnoProgramadoFecha)->format('Y-m-d'),
            'hora_entrada' => $turno->TurnoProgramadoHoraEntrada === null ? ($turno->relationLoaded('turno') ? substr((string) $turno->turno?->TurnoHoraEntrada, 0, 5) : null) : substr((string) $turno->TurnoProgramadoHoraEntrada, 0, 5),
            'hora_salida' => $turno->TurnoProgramadoHoraSalida === null ? ($turno->relationLoaded('turno') ? substr((string) $turno->turno?->TurnoHoraSalida, 0, 5) : null) : substr((string) $turno->TurnoProgramadoHoraSalida, 0, 5),
            'es_guardia' => (bool) $turno->TurnoProgramadoEsGuardia,
            'estado' => $turno->TurnoProgramadoEstado,
            'turno' => $turno->relationLoaded('turno') && $turno->turno ? [
                'id' => $turno->turno->TurnoId,
                'codigo' => $turno->turno->TurnoCodigo,
                'nombre' => $turno->turno->TurnoNombre,
            ] : null,
            'programacion_periodo_id' => $turno->relationLoaded('programacionTrabajador') ? $turno->programacionTrabajador?->ProgramacionPeriodoId : null,
            'programacion' => $turno->relationLoaded('programacionTrabajador') && $turno->programacionTrabajador?->relationLoaded('periodo') && $turno->programacionTrabajador->periodo ? [
                'id' => $turno->programacionTrabajador->periodo->ProgramacionPeriodoId,
                'codigo' => $turno->programacionTrabajador->periodo->ProgramacionPeriodoCodigo,
                'eess_id' => $turno->programacionTrabajador->periodo->EessId,
                'estado' => $turno->programacionTrabajador->periodo->ProgramacionPeriodoEstado,
            ] : null,
        ];
    }

    /** Trabajador y vinculo de quien solicita o reemplaza. */
    private function resumenDePersona($vinculo): ?array
    {
        if ($vinculo === null) {
            return null;
        }

        return [
            'vinculo_id' => $vinculo->VinculoLaboralId,
            'vinculo_codigo' => $vinculo->VinculoLaboralCodigo,
            'eess_id' => $vinculo->EessId,
            'trabajador_id' => $vinculo->TrabajadorId,
            'numero_documento' => $vinculo->relationLoaded('trabajador') ? $vinculo->trabajador?->TrabajadorNumeroDocumento : null,
            'nombre_completo' => $vinculo->relationLoaded('trabajador') && $vinculo->trabajador
                ? trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $vinculo->trabajador->TrabajadorNombreCompleto))) : null,
        ];
    }
}
