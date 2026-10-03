<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Configuracion\Turno;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Models\Programacion\TurnoProgramado;
use App\Services\ProgramacionPeriodoService;
use App\Services\TurnoProgramadoService;
use Illuminate\Validation\Validator;

/**
 * Turno de un trabajador en una fecha de su programacion. Se programa mientras la programacion del periodo es un
 * borrador (RIT, Art. 16); despues solo cambia por cambio de turno. Las guardias duran 12 horas y las hacen solo el
 * personal D.L. 276 y el SERUMS; no se programan 24 horas continuas (RIT, Art. 20).
 *
 * Un turno puede traer sus propias horas (p. ej. una guardia de 12 horas con otra hora de inicio); sin ellas hereda
 * las del Turno. El estado no se envia: nace PROGRAMADO y cambia con cumplir, con un cambio de turno o al anular la
 * programacion.
 */
class TurnoProgramadoRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    public function rules(): array
    {
        return [
            'ProgramacionTrabajadorId' => [$this->obligatorio(), 'integer', $this->existe(ProgramacionTrabajador::class)],
            'TurnoId' => [$this->obligatorio(), 'integer', $this->existeActivo(Turno::class, 'TurnoEstado', 'TurnoId')],
            'TurnoProgramadoFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'TurnoProgramadoHoraEntrada' => ['nullable', 'date_format:H:i,H:i:s'],
            'TurnoProgramadoHoraSalida' => ['nullable', 'date_format:H:i,H:i:s'],
            'TurnoProgramadoEsGuardia' => $this->booleano(),
            'TurnoProgramadoObservacion' => $this->texto(500),
        ];
    }

    public function messages(): array
    {
        return [
            'TurnoProgramadoFecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'TurnoProgramadoHoraEntrada.date_format' => 'La hora de entrada debe tener el formato HH:MM.',
            'TurnoProgramadoHoraSalida.date_format' => 'La hora de salida debe tener el formato HH:MM.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'TurnoProgramadoEstado');
            $registro = $this->registro();
            if ($registro && ($motivo = ProgramacionPeriodoService::motivoDeBloqueo($registro->programacionTrabajador->periodo))) {
                $validator->errors()->add('TurnoProgramadoFecha', $motivo);
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $programacion = ProgramacionTrabajador::query()->with(['periodo', 'vinculoLaboral'])->find($this->valorEfectivo('ProgramacionTrabajadorId'));
            $turno = Turno::query()->find($this->valorEfectivo('TurnoId'));
            $periodo = $programacion->periodo;
            $vinculo = $programacion->vinculoLaboral;
            $fecha = $this->fechaEfectiva('TurnoProgramadoFecha');

            if ($motivo = ProgramacionPeriodoService::motivoDeBloqueo($periodo)) {
                $validator->errors()->add('ProgramacionTrabajadorId', $motivo);

                return;
            }

            if ($fecha < $periodo->ProgramacionPeriodoFechaInicio->toDateString() || $fecha > $periodo->ProgramacionPeriodoFechaFin->toDateString()) {
                $validator->errors()->add('TurnoProgramadoFecha', 'La fecha está fuera del período de la programación ('
                    .$this->fechaCorta($periodo->ProgramacionPeriodoFechaInicio->toDateString()).' al '.$this->fechaCorta($periodo->ProgramacionPeriodoFechaFin->toDateString()).').');

                return;
            }
            $this->vinculoVigenteEn($validator, 'TurnoProgramadoFecha', $fecha, null, $vinculo->VinculoLaboralId);
            $this->rechazaPeriodoCerrado($validator, 'TurnoProgramadoFecha', $fecha);

            [$entrada, $salida] = [$this->hora('TurnoProgramadoHoraEntrada'), $this->hora('TurnoProgramadoHoraSalida')];
            if (($entrada === null) !== ($salida === null)) {
                $validator->errors()->add($entrada === null ? 'TurnoProgramadoHoraEntrada' : 'TurnoProgramadoHoraSalida', 'Indica la hora de entrada y la de salida, o ninguna para usar las del turno.');
            }
            $entradaEfectiva = $entrada ?? substr((string) $turno->TurnoHoraEntrada, 0, 8);
            $salidaEfectiva = $salida ?? substr((string) $turno->TurnoHoraSalida, 0, 8);
            if ($entrada !== null && $salida !== null && $entrada === $salida) {
                $validator->errors()->add('TurnoProgramadoHoraSalida', 'La hora de salida no puede ser igual a la de entrada.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (TurnoProgramado::minutosEntre($entradaEfectiva, $salidaEfectiva) > TurnoProgramadoService::MAXIMO_MINUTOS_POR_TURNO) {
                $validator->errors()->add('TurnoProgramadoHoraSalida', 'Un turno dura como máximo 12 horas: no se programan guardias de 24 horas (RIT, Art. 20).');

                return;
            }

            $servicio = app(TurnoProgramadoService::class);
            if ($this->esGuardia($turno) && ($motivo = $servicio->motivoSiNoPuedeHacerGuardia($vinculo))) {
                $validator->errors()->add('TurnoProgramadoEsGuardia', $motivo);
            }

            $duplicado = TurnoProgramado::query()
                ->where('ProgramacionTrabajadorId', $programacion->ProgramacionTrabajadorId)
                ->whereDate('TurnoProgramadoFecha', $fecha)
                ->where('TurnoId', $turno->TurnoId)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($duplicado) {
                $validator->errors()->add('TurnoId', 'El trabajador ya tiene ese turno programado ese día.');

                return;
            }

            $conflicto = $servicio->conflictoDeAgenda($vinculo->TrabajadorId, $fecha, $entradaEfectiva, $salidaEfectiva, array_filter([$this->registroId()]));
            if ($conflicto) {
                $validator->errors()->add('TurnoProgramadoFecha', $conflicto);
            }
        });
    }

    /** Es guardia si el Turno lo es (no hay guardia que deje de serlo) o si se declara asi (p. ej. un reten). */
    private function esGuardia(Turno $turno): bool
    {
        return (bool) $turno->TurnoEsGuardia || ($this->exists('TurnoProgramadoEsGuardia') && $this->booleanoEfectivo('TurnoProgramadoEsGuardia'));
    }

    /** "HH:MM:SS" de la columna (enviada o actual), o null. */
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

        foreach (['TurnoProgramadoHoraEntrada', 'TurnoProgramadoHoraSalida'] as $campo) {
            if (isset($datos[$campo]) && strlen($datos[$campo]) === 5) {
                $datos[$campo] .= ':00';
            }
        }
        // La guardia se guarda ya resuelta: la del Turno o la declarada.
        if ($this->esCreacion() || array_key_exists('TurnoId', $datos) || array_key_exists('TurnoProgramadoEsGuardia', $datos)) {
            $datos['TurnoProgramadoEsGuardia'] = $this->esGuardia(Turno::query()->find($this->valorEfectivo('TurnoId')));
        }

        return $datos;
    }
}
