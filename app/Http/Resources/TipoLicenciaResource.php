<?php

namespace App\Http\Resources;

use App\Models\Solicitudes\TipoLicencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TipoLicencia
 */
class TipoLicenciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TipoLicenciaId,
            'codigo' => $this->TipoLicenciaCodigo,
            'nombre' => $this->TipoLicenciaNombre,
            'descripcion' => $this->TipoLicenciaDescripcion,
            'con_goce' => (bool) $this->TipoLicenciaConGoce,
            'maximo_dias' => $this->TipoLicenciaMaximoDias,
            'base_legal' => $this->TipoLicenciaBaseLegal,
            'activo' => (bool) $this->TipoLicenciaEstado,
            'sexo' => $this->TipoLicenciaSexo,
            'maximo_dias_cas' => $this->TipoLicenciaMaximoDiasCas,
            'maximo_dias_anio' => $this->TipoLicenciaMaximoDiasAnio,
            'a_cuenta_vacaciones' => (bool) $this->TipoLicenciaACuentaVacaciones,
            'exige_anticipacion' => (bool) $this->TipoLicenciaExigeAnticipacion,
        ];
    }
}
