<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TipoNotificacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TipoNotificacionId,
            'codigo' => $this->TipoNotificacionCodigo,
            'nombre' => $this->TipoNotificacionNombre,
            'descripcion' => $this->TipoNotificacionDescripcion,
            'roles_destino' => $this->TipoNotificacionRolesDestino,
            'alcance' => $this->TipoNotificacionAlcance,
            'al_interesado' => (bool) $this->TipoNotificacionAlInteresado,
            'envia_correo' => (bool) $this->TipoNotificacionEnviaCorreo,
            'activo' => (bool) $this->TipoNotificacionEstado,
        ];
    }
}
