<?php

namespace App\Http\Requests;

use App\Models\Configuracion\Horario;
use App\Models\Configuracion\TipoJornada;
use App\Models\Organizacion\EstablecimientoSalud;

class HorarioRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoJornadaId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(TipoJornada::class, 'TipoJornadaEstado', 'TipoJornadaId'),
            ],
            // NULL = horario institucional de toda la Red.
            'EessId' => ['nullable', 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'HorarioCodigo' => $this->codigoUnico(Horario::class, 'HorarioCodigo', 30),
            'HorarioNombre' => $this->nombreUnico(Horario::class, 'HorarioNombre', 150),
            'HorarioDescripcion' => $this->texto(300),
            'HorarioEsRotativo' => $this->booleano(),
            'HorarioEstado' => $this->booleano(),
        ];
    }
}
