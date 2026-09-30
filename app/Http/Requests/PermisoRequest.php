<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Permiso;

class PermisoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'PermisoCodigo' => $this->codigoUnico(Permiso::class, 'PermisoCodigo', 100),
            'PermisoNombre' => $this->nombreUnico(Permiso::class, 'PermisoNombre', 150),
            'PermisoDescripcion' => $this->texto(300),
            'PermisoModulo' => $this->texto(60),
            'PermisoEstado' => $this->booleano(),
        ];
    }
}
