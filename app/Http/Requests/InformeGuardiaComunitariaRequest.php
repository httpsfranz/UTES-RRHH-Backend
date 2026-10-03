<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\InformeGuardiaComunitaria;
use App\Models\Programacion\TurnoProgramado;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Validation\Validator;

/**
 * Informe de guardia comunitaria (RIT, Art. 20): lo presenta de manera personal quien realizo la guardia, con las
 * actividades realizadas, visado por el jefe del establecimiento y el responsable de control de asistencia, hasta el
 * dia 05 de cada mes. La guardia comunitaria la hacen solo el personal nombrado y contratado bajo el D.L. 276 y el
 * SERUMS de presupuesto nacional; dura 12 horas.
 */
class InformeGuardiaComunitariaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Duracion de una guardia comunitaria diurna (RIT, Art. 20). */
    public const MAXIMO_MINUTOS = 720;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            // Turno programado que origina la guardia.
            'TurnoProgramadoId' => ['nullable', 'integer', $this->existe(TurnoProgramado::class)],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'InformeGuardiaComunitariaFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'InformeGuardiaComunitariaHoraInicio' => ['nullable', 'date_format:H:i,H:i:s'],
            'InformeGuardiaComunitariaHoraFin' => ['nullable', 'date_format:H:i,H:i:s'],
            'InformeGuardiaComunitariaDescripcion' => [$this->obligatorio(), 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'InformeGuardiaComunitariaFecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'InformeGuardiaComunitariaHoraInicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'InformeGuardiaComunitariaHoraFin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
        ];
    }

    /**
     * - Solo personal del D.L. 276 o SERUMS hace guardia comunitaria; el vinculo vigente ese dia; fecha no futura.
     * - La guardia dura hasta 12 horas y la hora de fin es posterior a la de inicio.
     * - Un solo informe (no rechazado ni anulado) por trabajador y dia.
     * - Solo se modifica pendiente; el estado no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'InformeGuardiaComunitariaEstado');
            $this->soloSiPendiente($validator, 'InformeGuardiaComunitariaEstado', 'InformeGuardiaComunitariaEstado', 'El informe');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fecha = $this->fechaEfectiva('InformeGuardiaComunitariaFecha');
            if ($fecha > now()->toDateString()) {
                $validator->errors()->add('InformeGuardiaComunitariaFecha', 'El informe no puede ser de una fecha futura.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'InformeGuardiaComunitariaFecha', $fecha);

            $vinculo = VinculoLaboral::query()->with(['regimenLaboral', 'condicionLaboral'])->find($this->valorEfectivo('VinculoLaboralId'));
            if ($vinculo) {
                $habilitado = $vinculo->regimenLaboral?->RegimenLaboralCodigo === 'DL276'
                    || in_array($vinculo->condicionLaboral?->CondicionLaboralCodigo, ['SERUMS_REM', 'SERUMS_EQUIV'], true);
                if (! $habilitado) {
                    $validator->errors()->add('VinculoLaboralId', 'La guardia comunitaria la realiza solo el personal nombrado o contratado bajo el D.L. 276 y el SERUMS (RIT, Art. 20).');
                }
            }

            $turno = TurnoProgramado::query()->with('programacionTrabajador')->find($this->valorEfectivo('TurnoProgramadoId'));
            if ($turno && ($turno->programacionTrabajador->VinculoLaboralId !== (int) $this->valorEfectivo('VinculoLaboralId')
                || $turno->TurnoProgramadoFecha->toDateString() !== $fecha || ! $turno->TurnoProgramadoEsGuardia)) {
                $validator->errors()->add('TurnoProgramadoId', 'El turno programado debe ser una guardia del mismo trabajador y del mismo día del informe.');
            }

            $inicio = $this->hora('InformeGuardiaComunitariaHoraInicio');
            $fin = $this->hora('InformeGuardiaComunitariaHoraFin');
            if ($inicio && $fin) {
                $minutos = (strtotime("1970-01-01 {$fin}") - strtotime("1970-01-01 {$inicio}")) / 60;
                if ($minutos <= 0) {
                    $validator->errors()->add('InformeGuardiaComunitariaHoraFin', 'La hora de fin debe ser posterior a la de inicio.');
                } elseif ($minutos > self::MAXIMO_MINUTOS) {
                    $validator->errors()->add('InformeGuardiaComunitariaHoraFin', 'La guardia comunitaria dura como máximo 12 horas (RIT, Art. 20).');
                }
            }

            $duplicado = InformeGuardiaComunitaria::query()
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->whereDate('InformeGuardiaComunitariaFecha', $fecha)
                ->whereNotIn('InformeGuardiaComunitariaEstado', ['RECHAZADO', 'ANULADO'])
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($duplicado) {
                $validator->errors()->add('InformeGuardiaComunitariaFecha', 'El trabajador ya tiene un informe pendiente o aprobado de ese día.');
            }
        });
    }

    private function hora(string $campo): ?string
    {
        $valor = $this->valorEfectivo($campo);
        if (blank($valor)) {
            return null;
        }

        return strlen((string) $valor) === 5 ? "{$valor}:00" : substr((string) $valor, 0, 8);
    }

    public function datos(): array
    {
        $datos = $this->validated();

        foreach (['InformeGuardiaComunitariaHoraInicio', 'InformeGuardiaComunitariaHoraFin'] as $campo) {
            if (isset($datos[$campo]) && strlen($datos[$campo]) === 5) {
                $datos[$campo] .= ':00';
            }
        }

        return $datos;
    }
}
