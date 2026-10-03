<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Http\Requests\Concerns\ReglasDeVacaciones;
use App\Models\Solicitudes\DescansoMedico;
use App\Models\Soporte\DocumentoSustento;
use App\Models\Vacaciones\GoceVacacional;
use App\Models\Vacaciones\RolVacacional;
use Illuminate\Validation\Validator;

/**
 * Solicitud de goce de un descanso programado en el Rol de Vacaciones. RIT, Art. 70 a 73: el descanso no se otorga a
 * quien esta incapacitado por enfermedad o accidente; el goce en periodos menores de 7 dias es un fraccionamiento que
 * se solicita por escrito (documento que lo sustenta). Nace PENDIENTE; aprobarla descuenta los dias del periodo vacacional.
 * Los dias salen de las fechas (calendario) y el estado cambia con aprobar, rechazar o anular.
 */
class GoceVacacionalRequest extends CatalogoRequest
{
    use ReglasDeNegocio;
    use ReglasDeVacaciones;

    /** Debajo de estos dias calendario el goce es un fraccionamiento (RIT, Art. 72 y 73). */
    public const DIAS_MINIMOS_SIN_FRACCIONAR = 7;

    public function rules(): array
    {
        return [
            'RolVacacionalId' => [$this->obligatorio(), 'integer', $this->existe(RolVacacional::class)],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'GoceVacacionalFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'GoceVacacionalFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'GoceVacacionalDias' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'GoceVacacionalFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'GoceVacacionalFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
            'GoceVacacionalDias.integer' => 'Los días del goce son días calendario enteros.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'GoceVacacionalEstado');
            $this->soloSiPendiente($validator, 'GoceVacacionalEstado', 'GoceVacacionalEstado', 'La solicitud');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rol = RolVacacional::query()->with('periodoVacacional.vinculoLaboral')->find($this->valorEfectivo('RolVacacionalId'));
            $inicio = $this->fechaEfectiva('GoceVacacionalFechaInicio');
            $fin = $this->fechaEfectiva('GoceVacacionalFechaFin');

            if ($fin < $inicio) {
                $validator->errors()->add('GoceVacacionalFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }
            $dias = $this->diasCalendario($inicio, $fin);
            if ($this->filled('GoceVacacionalDias') && (int) $this->input('GoceVacacionalDias') !== $dias) {
                $validator->errors()->add('GoceVacacionalDias', "Del {$this->fechaCorta($inicio)} al {$this->fechaCorta($fin)} son {$dias} días calendario.");

                return;
            }

            if ($rol->RolVacacionalEstado !== 'PROGRAMADO') {
                $validator->errors()->add('RolVacacionalId', 'La programación vacacional está '.match ($rol->RolVacacionalEstado) {
                    'GOZADO' => 'gozada', 'REPROGRAMADO' => 'reprogramada', default => 'anulada',
                }.': solo se solicita goce de una programación vigente.');

                return;
            }
            $periodo = $rol->periodoVacacional;
            if ($periodo->PeriodoVacacionalEstado !== 'ABIERTO') {
                $validator->errors()->add('RolVacacionalId', 'El período vacacional ya no está abierto.');

                return;
            }

            $finRol = $rol->RolVacacionalFechaFinProgramada?->toDateString() ?? $this->fechaFinDelDescanso($rol->RolVacacionalFechaProgramada->toDateString(), (int) $rol->RolVacacionalDias);
            if ($inicio < $rol->RolVacacionalFechaProgramada->toDateString() || $fin > $finRol) {
                $validator->errors()->add('GoceVacacionalFechaInicio', 'El goce debe caer dentro de lo programado ('.$this->fechaCorta($rol->RolVacacionalFechaProgramada->toDateString()).' al '.$this->fechaCorta($finRol).'); para otras fechas reprograma el descanso.');

                return;
            }

            $vigentes = GoceVacacional::query()
                ->where('RolVacacionalId', $rol->RolVacacionalId)
                ->whereIn('GoceVacacionalEstado', ['PENDIENTE', 'APROBADO'])
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->get();
            if ($vigentes->contains(fn (GoceVacacional $g) => $g->GoceVacacionalFechaInicio->toDateString() <= $fin && $g->GoceVacacionalFechaFin->toDateString() >= $inicio)) {
                $validator->errors()->add('GoceVacacionalFechaInicio', 'Ya hay otro goce solicitado para esa programación que se cruza con esas fechas.');
            }
            $solicitados = (float) $vigentes->sum('GoceVacacionalDias') + $dias;
            if ($solicitados > (float) $rol->RolVacacionalDias) {
                $validator->errors()->add('GoceVacacionalFechaFin', "Con este goce se solicitarían {$solicitados} días y la programación es de {$rol->RolVacacionalDias}.");
            }

            $this->rechazaPeriodoCerrado($validator, 'GoceVacacionalFechaInicio', $inicio, $fin);
            $incapacitado = DescansoMedico::query()
                ->where('VinculoLaboralId', $periodo->VinculoLaboralId)
                ->where('DescansoMedicoEstado', 'APROBADO')
                ->whereDate('DescansoMedicoFechaInicio', '<=', $fin)
                ->whereDate('DescansoMedicoFechaFin', '>=', $inicio)
                ->exists();
            if ($incapacitado) {
                $validator->errors()->add('GoceVacacionalFechaInicio', 'El descanso vacacional no se otorga cuando el servidor está incapacitado por enfermedad o accidente: hay un descanso médico aprobado en esas fechas (RIT, Art. 70).');
            }
            if ($dias < self::DIAS_MINIMOS_SIN_FRACCIONAR && $this->valorEfectivo('DocumentoSustentoId') === null) {
                $validator->errors()->add('DocumentoSustentoId', 'Un goce menor de '.self::DIAS_MINIMOS_SIN_FRACCIONAR.' días es un fraccionamiento y se solicita por escrito: adjunta el documento (RIT, Art. 72 y 73).');
            }
        });
    }

    public function datos(): array
    {
        $datos = $this->validated();
        $datos['GoceVacacionalDias'] = $this->diasCalendario(
            $datos['GoceVacacionalFechaInicio'] ?? $this->fechaEfectiva('GoceVacacionalFechaInicio'),
            $datos['GoceVacacionalFechaFin'] ?? $this->fechaEfectiva('GoceVacacionalFechaFin'),
        );

        return $datos;
    }
}
