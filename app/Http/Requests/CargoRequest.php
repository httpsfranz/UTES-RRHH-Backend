<?php

namespace App\Http\Requests;

use App\Models\Personal\Cargo;
use App\Models\Personal\GrupoOcupacional;
use App\Rules\Codigo;

class CargoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'GrupoOcupacionalId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(GrupoOcupacional::class, 'GrupoOcupacionalEstado', 'GrupoOcupacionalId'),
            ],
            // Opcional, pero unico cuando existe (indice filtrado UX_Cargo_Codigo).
            'CargoCodigo' => ['nullable', 'string', 'max:30', new Codigo, $this->unico(Cargo::class, 'CargoCodigo')],
            'CargoNombre' => $this->nombreUnico(Cargo::class, 'CargoNombre', 150),
            'CargoDescripcion' => $this->texto(300),
            'CargoEsJefatura' => $this->booleano(),
            'CargoEstado' => $this->booleano(),
        ];
    }
}
