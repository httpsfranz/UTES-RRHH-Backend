<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Rol;

class RolRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'RolCodigo' => $this->codigoUnico(Rol::class, 'RolCodigo', 50),
            'RolNombre' => $this->nombreUnico(Rol::class, 'RolNombre', 100),
            'RolDescripcion' => $this->texto(300),
            'RolEstado' => $this->booleano(),
        ];
    }
}
