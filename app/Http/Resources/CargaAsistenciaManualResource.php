<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CargaAsistenciaManualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->CargaAsistenciaManualId,
            'usuario_id' => $this->UsuarioId,
            'eess_id' => $this->EessId,
            'documento_sustento_id' => $this->DocumentoSustentoId,
            'fecha' => optional($this->CargaAsistenciaManualFecha)->format('Y-m-d H:i:s'),
            'nombre_archivo' => $this->CargaAsistenciaManualNombreArchivo,
            'registros' => $this->CargaAsistenciaManualRegistros,
            'observacion' => $this->CargaAsistenciaManualObservacion,
            'estado' => $this->CargaAsistenciaManualEstado,
            // Anulada = "inactiva": el listado la muestra apagada.
            'activo' => ! in_array($this->CargaAsistenciaManualEstado, ['ANULADO', 'ANULADA'], true),
            'marcaciones_registradas' => isset($this->marcaciones_count) ? (int) $this->marcaciones_count : null,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->UsuarioId,
                'nombre' => $this->usuario->UsuarioNombre,
            ] : null),
            'eess' => $this->whenLoaded('eess', fn () => $this->eess ? [
                'id' => $this->eess->EessId,
                'codigo' => $this->eess->EessCodigo,
                'nombre' => $this->eess->EessNombre,
            ] : null),
            'documento' => $this->whenLoaded('documento', fn () => $this->documento ? [
                'id' => $this->documento->DocumentoSustentoId,
                'nombre' => $this->documento->DocumentoSustentoNombre,
            ] : null),
        ];
    }
}
