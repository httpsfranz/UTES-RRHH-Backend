<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Organizacion\ResponsableEess;
use App\Models\Organizacion\TipoResponsabilidad;
use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Responsable de un establecimiento (jefe, responsable de personal, de programacion, de asistencia...) con vigencia, para
 * conservar el historico de designaciones (RIT, Art. 21 y 28: el jefe inmediato y el responsable de control de asistencia).
 */
class ResponsableEessRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'EessId' => [$this->obligatorio(), 'integer', $this->existeActivo(EstablecimientoSalud::class, 'EessEstado', 'EessId')],
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoResponsabilidadId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoResponsabilidad::class, 'TipoResponsabilidadEstado', 'TipoResponsabilidadId')],
            // Resolucion o memorando de designacion.
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'ResponsableEessFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'ResponsableEessFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'ResponsableEessDocumentoNumero' => ['nullable', 'string', 'max:60'],
            'ResponsableEessObservacion' => $this->texto(500),
            'ResponsableEessEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'ResponsableEessFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'ResponsableEessFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - fin >= inicio; el vinculo del designado vigente al iniciar la designacion.
     * - Un solo responsable vigente por establecimiento y tipo de responsabilidad: no se superponen designaciones
     *   activas (tampoco al reactivar una); para cambiar de responsable se cierra la anterior.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('ResponsableEessFechaInicio');
            $fin = $this->fechaEfectiva('ResponsableEessFechaFin');
            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('ResponsableEessFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'VinculoLaboralId', $inicio, $fin ?? $inicio);

            if ($this->booleanoEfectivo('ResponsableEessEstado')) {
                $solapa = $this->haySuperposicion(
                    ResponsableEess::class, 'ResponsableEessFechaInicio', 'ResponsableEessFechaFin', $inicio, $fin,
                    ['EessId' => $this->valorEfectivo('EessId'), 'TipoResponsabilidadId' => $this->valorEfectivo('TipoResponsabilidadId'), 'ResponsableEessEstado' => 1],
                );
                if ($solapa) {
                    $validator->errors()->add('TipoResponsabilidadId', 'El establecimiento ya tiene un responsable de ese tipo en esas fechas: cierra la designación anterior antes de crear otra.');
                }
            }
        });
    }
}
