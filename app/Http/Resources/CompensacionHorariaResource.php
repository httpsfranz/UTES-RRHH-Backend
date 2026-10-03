<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompensacionHorariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CompensacionHorariaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'tipo_compensacion_id' => $this->TipoCompensacionId,
            'asistencia_diaria_id' => $this->AsistenciaDiariaId,
            'autorizado_por' => $this->CompensacionHorariaAutorizadoPor,
            'fecha_generacion' => optional($this->CompensacionHorariaFechaGeneracion)->format('Y-m-d H:i:s'),
            'horas_generadas' => $this->CompensacionHorariaHorasGeneradas,
            'horas_devueltas' => $this->CompensacionHorariaHorasDevueltas,
            'fecha_limite' => optional($this->CompensacionHorariaFechaLimite)->format('Y-m-d'),
            'autorizado_previamente' => (bool) $this->CompensacionHorariaAutorizadoPreviamente,
            'observacion' => $this->CompensacionHorariaObservacion,
            'estado' => $this->CompensacionHorariaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->CompensacionHorariaEstado, ['ANULADO', 'ANULADA'], true),
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
                'id' => $this->tipo->TipoCompensacionId,
                'codigo' => $this->tipo->TipoCompensacionCodigo,
                'nombre' => $this->tipo->TipoCompensacionNombre,
            ] : null),
            'usuario_autorizacion' => $this->whenLoaded('autorizadoPor', fn () => $this->autorizadoPor ? [
                'id' => $this->autorizadoPor->UsuarioId,
                'nombre' => $this->autorizadoPor->UsuarioNombre,
            ] : null),
            'horas_pendientes' => round((float) $this->CompensacionHorariaHorasGeneradas - (float) $this->CompensacionHorariaHorasDevueltas, 2),
            'vencida' => ! in_array($this->CompensacionHorariaEstado, ['CONSUMIDO', 'ANULADO'], true) && $this->CompensacionHorariaFechaLimite !== null && $this->CompensacionHorariaFechaLimite->lt(now()->startOfDay()),
        ];
    }
}
