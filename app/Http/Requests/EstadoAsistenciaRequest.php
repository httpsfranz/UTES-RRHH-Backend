<?php

namespace App\Http\Requests;

use App\Models\Asistencia\EstadoAsistencia;

class EstadoAsistenciaRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'EstadoAsistenciaCodigo' => $this->codigoUnico(EstadoAsistencia::class, 'EstadoAsistenciaCodigo', 30),
            'EstadoAsistenciaNombre' => $this->nombreUnico(EstadoAsistencia::class, 'EstadoAsistenciaNombre', 100),
            'EstadoAsistenciaDescripcion' => $this->texto(250),
            'EstadoAsistenciaEsFalta' => $this->booleano(),
            'EstadoAsistenciaEsDescontable' => $this->booleano(),
            'EstadoAsistenciaEsLaborable' => $this->booleano(),
            'EstadoAsistenciaEstado' => $this->booleano(),
        ];
    }
}
