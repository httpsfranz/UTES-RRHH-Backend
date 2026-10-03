<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConstatacionDomiciliariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ConstatacionDomiciliariaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'descanso_medico_id' => $this->DescansoMedicoId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'fecha' => optional($this->ConstatacionDomiciliariaFecha)->format('Y-m-d'),
            'direccion' => $this->ConstatacionDomiciliariaDireccion,
            'resultado' => $this->ConstatacionDomiciliariaResultado,
            'estado' => $this->ConstatacionDomiciliariaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->ConstatacionDomiciliariaEstado, ['ANULADO', 'ANULADA'], true),
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
            'descanso' => $this->whenLoaded('descansoMedico', fn () => $this->descansoMedico ? [
                'id' => $this->descansoMedico->DescansoMedicoId,
                'numero_citt' => $this->descansoMedico->DescansoMedicoNumeroCitt,
                'fecha_inicio' => optional($this->descansoMedico->DescansoMedicoFechaInicio)->format('Y-m-d'),
                'fecha_fin' => optional($this->descansoMedico->DescansoMedicoFechaFin)->format('Y-m-d'),
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
            'usuario_registro' => $this->whenLoaded('usuarioRegistro', fn () => $this->usuarioRegistro ? [
                'id' => $this->usuarioRegistro->UsuarioId,
                'nombre' => $this->usuarioRegistro->UsuarioNombre,
            ] : null),
        ];
    }
}
