<?php

namespace App\Http\Resources;

use App\Services\RevisionConsolidadoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class ConsolidadoAsistenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ConsolidadoAsistenciaId,
            'periodo_asistencia_id' => $this->PeriodoAsistenciaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'dias_trabajados' => $this->ConsolidadoAsistenciaDiasTrabajados,
            'dias_falta' => $this->ConsolidadoAsistenciaDiasFalta,
            'dias_falta_justificada' => $this->ConsolidadoAsistenciaDiasFaltaJustificada,
            'minutos_tardanza' => $this->ConsolidadoAsistenciaMinutosTardanza,
            'minutos_extra' => $this->ConsolidadoAsistenciaMinutosExtra,
            'fecha_generacion' => optional($this->ConsolidadoAsistenciaFechaGeneracion)->format('Y-m-d H:i:s'),
            'estado' => $this->ConsolidadoAsistenciaEstado,
            // Revision: observacion (motivo de la correccion pedida o nota de conformidad), quien y cuando reviso.
            'observacion' => $this->ConsolidadoAsistenciaObservacion,
            'revisado_por' => $this->ConsolidadoAsistenciaRevisadoPor ? DB::table('Seguridad.Usuario')->where('UsuarioId', $this->ConsolidadoAsistenciaRevisadoPor)->value('UsuarioNombre') : null,
            'fecha_revision' => $this->ConsolidadoAsistenciaFechaRevision ? substr((string) $this->ConsolidadoAsistenciaFechaRevision, 0, 19) : null,
            // Plazo del RIT (Art. 16) para consolidar segun la condicion laboral: dia 1, 3 o 5 del mes siguiente.
            'fecha_limite_rit' => $this->whenLoaded('periodo', fn () => $this->periodo ? RevisionConsolidadoService::fechaLimite((int) $this->periodo->PeriodoAsistenciaAnio, (int) $this->periodo->PeriodoAsistenciaMes,
                DB::table('Personal.VinculoLaboral as v')->join('Personal.CondicionLaboral as c', 'c.CondicionLaboralId', '=', 'v.CondicionLaboralId')->where('v.VinculoLaboralId', $this->VinculoLaboralId)->value('c.CondicionLaboralCodigo')) : null),
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ConsolidadoAsistenciaEstado, ['ANULADO', 'ANULADA'], true),
            'trabajador' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral?->relationLoaded('trabajador') && $this->vinculoLaboral->trabajador ? [
                'id' => $this->vinculoLaboral->trabajador->TrabajadorId,
                'numero_documento' => $this->vinculoLaboral->trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->vinculoLaboral->trabajador->TrabajadorNombreCompleto))),
            ] : null),
            'vinculo' => $this->whenLoaded('vinculoLaboral', fn () => $this->vinculoLaboral ? [
                'id' => $this->vinculoLaboral->VinculoLaboralId,
                'codigo' => $this->vinculoLaboral->VinculoLaboralCodigo,
                'eess_id' => $this->vinculoLaboral->EessId,
                'eess' => $this->vinculoLaboral->relationLoaded('eess') && $this->vinculoLaboral->eess ? ['id' => $this->vinculoLaboral->eess->EessId, 'nombre' => $this->vinculoLaboral->eess->EessNombre] : null,
            ] : null),
            'periodo' => $this->whenLoaded('periodo', fn () => $this->periodo ? [
                'id' => $this->periodo->PeriodoAsistenciaId,
                'anio' => (int) $this->periodo->PeriodoAsistenciaAnio,
                'mes' => (int) $this->periodo->PeriodoAsistenciaMes,
                'estado' => $this->periodo->PeriodoAsistenciaEstado,
            ] : null),
        ];
    }
}
