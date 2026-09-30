<?php

namespace App\Http\Requests;

use App\Models\Organizacion\Microred;
use App\Rules\TelefonoPeruano;
use App\Rules\Ubigeo;

class MicroredRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'MicroredCodigo' => $this->codigoUnico(Microred::class, 'MicroredCodigo', 30),
            'MicroredNombre' => $this->nombreUnico(Microred::class, 'MicroredNombre', 150),
            'MicroredDistrito' => $this->texto(100),
            'MicroredUbigeo' => ['nullable', new Ubigeo],
            'MicroredDireccion' => $this->texto(300),
            'MicroredTelefono' => ['nullable', new TelefonoPeruano],
            'MicroredDescripcion' => $this->texto(300),
            'MicroredEstado' => $this->booleano(),
        ];
    }
}
