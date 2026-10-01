<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrabajadorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TrabajadorId,
            'tipo_documento_id' => $this->TipoDocumentoIdentidadId,
            'profesion_id' => $this->ProfesionId,
            'numero_documento' => $this->TrabajadorNumeroDocumento,
            'nombres' => $this->TrabajadorNombres,
            'apellido_paterno' => $this->TrabajadorApellidoPaterno,
            'apellido_materno' => $this->TrabajadorApellidoMaterno,
            // La base arma "Paterno Materno, Nombres" y deja espacios de mas cuando no hay apellido materno.
            'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->TrabajadorNombreCompleto))),
            'sexo' => $this->TrabajadorSexo,
            'fecha_nacimiento' => optional($this->TrabajadorFechaNacimiento)->format('Y-m-d'),
            'correo' => $this->TrabajadorCorreo,
            'telefono' => $this->TrabajadorTelefono,
            'direccion' => $this->TrabajadorDireccion,
            'foto_ruta' => $this->TrabajadorFotoRuta,
            'fecha_registro' => optional($this->TrabajadorFechaRegistro)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->TrabajadorEstado,
            'tipo_documento' => $this->whenLoaded('tipoDocumento', fn () => [
                'id' => $this->tipoDocumento->TipoDocumentoIdentidadId,
                'codigo' => $this->tipoDocumento->TipoDocumentoIdentidadCodigo,
                'nombre' => $this->tipoDocumento->TipoDocumentoIdentidadNombre,
                'abreviatura' => $this->tipoDocumento->TipoDocumentoIdentidadAbreviatura,
            ]),
            'profesion' => $this->whenLoaded('profesion', fn () => $this->profesion ? [
                'id' => $this->profesion->ProfesionId,
                'nombre' => $this->profesion->ProfesionNombre,
            ] : null),
        ];
    }
}
