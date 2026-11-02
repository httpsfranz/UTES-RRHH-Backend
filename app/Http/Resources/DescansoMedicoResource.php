<?php

namespace App\Http\Resources;

use App\Support\AccesoUsuario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DescansoMedicoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->DescansoMedicoId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'numero_citt' => $this->DescansoMedicoNumeroCitt,
            // Dato de salud (RIT, Art. 83.19): solo quien resuelve la solicitud o el propio trabajador.
            'diagnostico' => $this->puedeVerDiagnostico($request) ? $this->DescansoMedicoDiagnostico : null,
            'fecha_inicio' => optional($this->DescansoMedicoFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->DescansoMedicoFechaFin)->format('Y-m-d'),
            'observacion' => $this->DescansoMedicoObservacion,
            'fecha_registro' => optional($this->DescansoMedicoFechaRegistro)->format('Y-m-d H:i:s'),
            'estado' => $this->DescansoMedicoEstado,
            'es_extemporaneo' => (bool) $this->DescansoMedicoEsExtemporaneo,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->DescansoMedicoEstado, ['ANULADO', 'ANULADA'], true),
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
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }

    private function puedeVerDiagnostico(Request $request): bool
    {
        $usuario = $request->user();
        if ($usuario === null) {
            return true;
        }
        $acceso = AccesoUsuario::de($usuario);

        return $acceso->tiene('SOLICITUDES_APROBAR') || in_array((int) $this->VinculoLaboralId, $acceso->vinculoIds, true);
    }
}
