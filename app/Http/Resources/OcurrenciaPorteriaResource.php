<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OcurrenciaPorteriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trabajador = $this->relationLoaded('vinculoLaboral') ? $this->vinculoLaboral?->trabajador : null;

        return [
            'id' => $this->OcurrenciaPorteriaId,
            'eess_id' => $this->EessId,
            'vinculo_laboral_id' => $this->VinculoLaboralId,
            'usuario_id' => $this->UsuarioId,
            'fecha_hora' => optional($this->OcurrenciaPorteriaFechaHora)->format('Y-m-d H:i:s'),
            'tipo' => $this->OcurrenciaPorteriaTipo,
            'descripcion' => $this->OcurrenciaPorteriaDescripcion,
            'estado' => $this->OcurrenciaPorteriaEstado,
            // Anulada = "inactiva": el listado la muestra apagada y no se puede reactivar.
            'activo' => ! $this->estaAnulada(),
            'eess' => $this->whenLoaded('eess', fn () => [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ]),
            'trabajador' => $trabajador ? [
                'id' => $trabajador->TrabajadorId,
                'numero_documento' => $trabajador->TrabajadorNumeroDocumento,
                'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $trabajador->TrabajadorNombreCompleto))),
            ] : null,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
        ];
    }
}
