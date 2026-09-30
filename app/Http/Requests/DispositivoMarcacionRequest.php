<?php

namespace App\Http\Requests;

use App\Models\Biometria\DispositivoMarcacion;
use App\Models\Organizacion\EstablecimientoSalud;

class DispositivoMarcacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            // NULL = dispositivo no asignado a un establecimiento concreto.
            'EessId' => ['nullable', 'integer', $this->existe(EstablecimientoSalud::class)],
            'DispositivoMarcacionCodigo' => $this->codigoUnico(DispositivoMarcacion::class, 'DispositivoMarcacionCodigo', 50),
            'DispositivoMarcacionNombre' => $this->textoObligatorio(100),
            'DispositivoMarcacionTipo' => $this->textoObligatorio(50),
            'DispositivoMarcacionUbicacion' => $this->texto(200),
            'DispositivoMarcacionIp' => ['nullable', 'ip'],
            'DispositivoMarcacionEstado' => $this->booleano(),
        ];
    }
}
