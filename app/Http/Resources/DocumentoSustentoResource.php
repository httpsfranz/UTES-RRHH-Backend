<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentoSustentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->DocumentoSustentoId,
            'nombre'           => $this->DocumentoSustentoNombre,
            'ruta'             => $this->DocumentoSustentoRuta,
            'tipo'             => $this->DocumentoSustentoTipo,
            'extension'        => $this->DocumentoSustentoExtension,
            'tamano_bytes'     => $this->DocumentoSustentoTamanoBytes,
            'hash'             => $this->DocumentoSustentoHash,
            'fecha_registro'   => optional($this->DocumentoSustentoFechaRegistro)->format('Y-m-d H:i:s'),
        ];
    }
}
