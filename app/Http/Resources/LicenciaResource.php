<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->LicenciaId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'tipo_licencia_id' => $this->TipoLicenciaId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'usuario_registro_id' => $this->UsuarioRegistroId,
            'numero_resolucion' => $this->LicenciaNumeroResolucion,
            'fecha_inicio' => optional($this->LicenciaFechaInicio)->format('Y-m-d'),
            'fecha_fin' => optional($this->LicenciaFechaFin)->format('Y-m-d'),
            'motivo' => $this->LicenciaMotivo,
            'fecha_registro' => optional($this->LicenciaFechaRegistro)->format('Y-m-d H:i:s'),
            'estado' => $this->LicenciaEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->LicenciaEstado, ['ANULADO', 'ANULADA'], true),
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
                'id' => $this->tipo->TipoLicenciaId,
                'codigo' => $this->tipo->TipoLicenciaCodigo,
                'nombre' => $this->tipo->TipoLicenciaNombre,
                'con_goce' => (bool) $this->tipo->TipoLicenciaConGoce,
                'maximo_dias' => $this->tipo->TipoLicenciaMaximoDias,
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
