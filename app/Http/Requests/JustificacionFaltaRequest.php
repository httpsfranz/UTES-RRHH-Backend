<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Asistencia\ConceptoJustificacion;
use App\Models\Asistencia\JustificacionFalta;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Justificacion de faltas: un vinculo, un concepto y un rango de fechas. Nace PENDIENTE; se aprueba o rechaza con
 * las acciones del controlador (aprobarla enlaza las faltas de esos dias con la justificacion).
 */
class JustificacionFaltaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'ConceptoJustificacionId' => [$this->obligatorio(), 'integer', $this->existeActivo(ConceptoJustificacion::class, 'ConceptoJustificacionEstado', 'ConceptoJustificacionId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Quien registra. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'JustificacionFaltaFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'JustificacionFaltaFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'JustificacionFaltaDocumentoNumero' => ['nullable', 'string', 'max:60'],
            'JustificacionFaltaObservacion' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'JustificacionFaltaFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'JustificacionFaltaFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - fin >= inicio; el vinculo vigente en todo el rango; ningun dia en un periodo de asistencia cerrado.
     * - Si el concepto exige documento (ConceptoJustificacionRequiereDocumento), el documento es obligatorio.
     * - Un dia admite a lo sumo una justificacion: no se superpone con otra PENDIENTE o APROBADA del mismo vinculo.
     * - Solo se modifica mientras esta PENDIENTE; el estado no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('JustificacionFaltaFechaInicio');
            $fin = $this->fechaEfectiva('JustificacionFaltaFechaFin');

            $this->rechazaEstadoEnviado($validator, 'JustificacionFaltaEstado');
            $this->soloSiPendiente($validator, 'JustificacionFaltaEstado', 'JustificacionFaltaEstado', 'La justificación');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($inicio && $fin && $fin < $inicio) {
                $validator->errors()->add('JustificacionFaltaFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'VinculoLaboralId', $inicio, $fin);
            $this->rechazaPeriodoCerrado($validator, 'JustificacionFaltaFechaInicio', $inicio, $fin);

            $concepto = ConceptoJustificacion::query()->find($this->valorEfectivo('ConceptoJustificacionId'));
            if ($concepto?->ConceptoJustificacionRequiereDocumento && blank($this->valorEfectivo('DocumentoSustentoId'))) {
                $validator->errors()->add('DocumentoSustentoId', "El concepto \"{$concepto->ConceptoJustificacionNombre}\" exige adjuntar el documento de sustento.");
            }

            $solapa = $this->haySuperposicion(
                JustificacionFalta::class, 'JustificacionFaltaFechaInicio', 'JustificacionFaltaFechaFin', $inicio, $fin,
                ['VinculoLaboralId' => $this->valorEfectivo('VinculoLaboralId')],
                'JustificacionFaltaEstado', ['RECHAZADO', 'ANULADO'],
            );
            if ($solapa) {
                $validator->errors()->add('JustificacionFaltaFechaInicio', 'El trabajador ya tiene una justificación pendiente o aprobada que se superpone con esas fechas.');
            }
        });
    }
}
