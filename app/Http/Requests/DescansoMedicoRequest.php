<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Solicitudes\DescansoMedico;
use App\Models\Soporte\DocumentoSustento;
use App\Support\ReglasDeSolicitud;
use Illuminate\Validation\Validator;

/**
 * Descanso medico: nace de un CITT o certificado medico, alimenta el subsidio y dispara la constatacion domiciliaria
 * (RIT, Art. 23 d y 32). No es una licencia: tiene otro sustento y otro efecto economico.
 */
class DescansoMedicoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'DescansoMedicoNumeroCitt' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/.]*$/D'],
            'DescansoMedicoDiagnostico' => $this->texto(300),
            'DescansoMedicoFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'DescansoMedicoFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'DescansoMedicoObservacion' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'DescansoMedicoNumeroCitt.regex' => 'El número de CITT solo admite letras, números, guion, barra y punto.',
            'DescansoMedicoFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'DescansoMedicoFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - fin >= inicio; el vinculo vigente en todo el rango; sin superposicion con otro descanso pendiente o aprobado.
     * - Un CITT no se registra dos veces (mientras el anterior no este rechazado o anulado).
     * - Solo se modifica pendiente; el estado no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            if ($this->esCreacion()) {
                $this->reglasDePresentacion($validator);
            }
            $this->rechazaEstadoEnviado($validator, 'DescansoMedicoEstado');
            $this->soloSiPendiente($validator, 'DescansoMedicoEstado', 'DescansoMedicoEstado', 'El descanso médico');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $inicio = $this->fechaEfectiva('DescansoMedicoFechaInicio');
            $fin = $this->fechaEfectiva('DescansoMedicoFechaFin');
            if ($fin < $inicio) {
                $validator->errors()->add('DescansoMedicoFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'VinculoLaboralId', $inicio, $fin);

            $solapa = $this->haySuperposicion(
                DescansoMedico::class, 'DescansoMedicoFechaInicio', 'DescansoMedicoFechaFin', $inicio, $fin,
                ['VinculoLaboralId' => $this->valorEfectivo('VinculoLaboralId')], 'DescansoMedicoEstado', ['RECHAZADO', 'ANULADO'],
            );
            if ($solapa) {
                $validator->errors()->add('DescansoMedicoFechaInicio', 'El trabajador ya tiene un descanso médico pendiente o aprobado que se superpone con esas fechas.');
            }

            $citt = $this->valorEfectivo('DescansoMedicoNumeroCitt');
            if (filled($citt) && DescansoMedico::query()->where('DescansoMedicoNumeroCitt', $citt)
                ->whereNotIn('DescansoMedicoEstado', ['RECHAZADO', 'ANULADO'])
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))->exists()) {
                $validator->errors()->add('DescansoMedicoNumeroCitt', 'Ya existe un descanso médico con ese número de CITT.');
            }
        });
    }

    /**
     * Se presenta con CITT o certificado dentro de 3 dias habiles; luego, hasta 7 dias habiles mas como extemporaneo
     * justificado (Art. 32 y 64). Desde el dia 21 del anio se exige el CITT de EsSalud (Art. 32).
     */
    private function reglasDePresentacion(Validator $validator): void
    {
        $inicio = (string) $this->fechaEfectiva('DescansoMedicoFechaInicio');
        $fin = (string) $this->fechaEfectiva('DescansoMedicoFechaFin');
        $citt = $this->valorEfectivo('DescansoMedicoNumeroCitt');
        if (blank($citt) && blank($this->valorEfectivo('DocumentoSustentoId'))) {
            $validator->errors()->add('DocumentoSustentoId', 'Adjunta el certificado médico o indica el número de CITT (RIT, Art. 32).');
        }

        $plazo = ReglasDeSolicitud::situacionDelPlazo($inicio, ReglasDeSolicitud::DIAS_HABILES_DESCANSO_MEDICO);
        if ($plazo === 'vencido') {
            $validator->errors()->add('DescansoMedicoFechaInicio', 'El descanso médico se presenta en 3 días hábiles y su regularización vence a los 7 días hábiles siguientes (RIT, Art. 32 y 64).');
        } elseif ($plazo === 'extemporaneo' && blank($this->valorEfectivo('DescansoMedicoObservacion'))) {
            $validator->errors()->add('DescansoMedicoObservacion', 'Se presenta fuera de los 3 días hábiles: indica el motivo (RIT, Art. 32 y 64).');
        }

        $dias = (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1;
        $acumulados = ReglasDeSolicitud::diasDescansoEnElAnio((int) $this->valorEfectivo('VinculoLaboralId'), $inicio);
        if ($acumulados + $dias > ReglasDeSolicitud::DIAS_A_CARGO_DE_LA_ENTIDAD && blank($citt)) {
            $validator->errors()->add('DescansoMedicoNumeroCitt', 'El trabajador llegaría a '.($acumulados + $dias).' días en el año: desde el día 21 se exige el CITT de EsSalud (RIT, Art. 32).');
        }
    }

    /** @return array<string,mixed> */
    public function datos(): array
    {
        $datos = parent::datos();
        if ($this->esCreacion()) {
            $datos['DescansoMedicoEsExtemporaneo'] = ReglasDeSolicitud::situacionDelPlazo((string) $this->fechaEfectiva('DescansoMedicoFechaInicio'), ReglasDeSolicitud::DIAS_HABILES_DESCANSO_MEDICO) !== null;
        }

        return $datos;
    }
}
