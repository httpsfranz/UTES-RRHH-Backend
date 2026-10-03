<?php

namespace App\Services;

use App\Models\Personal\VinculoLaboral;
use App\Models\Programacion\TurnoProgramado;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Turnos programados de un trabajador. Reune las reglas de la agenda que comparten la programacion y el cambio de turno:
 * un trabajador no puede estar en dos turnos a la vez, no se programan 24 horas continuas (RIT, Art. 20) y la guardia
 * la hacen solo el personal D.L. 276 y el SERUMS (RIT, Art. 20).
 */
class TurnoProgramadoService
{
    /** Ningun turno programado dura mas de 12 horas (RIT, Art. 20: las guardias duran 12 horas, sin guardias de 24). */
    public const MAXIMO_MINUTOS_POR_TURNO = 720;

    /** Prohibido programar guardias de 24 horas continuas, ni con reemplazos que sumados las alcancen (RIT, Art. 20). */
    public const MINUTOS_CONTINUOS_PROHIBIDOS = 1440;

    /** Retira un turno de una programacion en borrador; una publicada solo cambia por cambio de turno (RIT, Art. 16). */
    public function eliminar(TurnoProgramado $turno): void
    {
        ProgramacionPeriodoService::exigirBorrador($turno->programacionTrabajador->periodo);
        $turno->delete();
    }

    /** El turno ya se realizo: de PROGRAMADO o REPROGRAMADO a CUMPLIDO. Solo en una programacion publicada. */
    public function cumplir(TurnoProgramado $turno): TurnoProgramado
    {
        if (! in_array($turno->TurnoProgramadoEstado, ['PROGRAMADO', 'REPROGRAMADO'], true)) {
            throw new DomainException('Solo se marca como cumplido un turno programado o reprogramado.');
        }
        if ($turno->programacionTrabajador->periodo->ProgramacionPeriodoEstado !== 'PUBLICADA') {
            throw new DomainException('Solo se marcan como cumplidos los turnos de una programación publicada.');
        }
        $turno->loadMissing('turno');
        if ($turno->inicio()?->gt(HoraLocal::ahora())) {
            throw new DomainException('El turno aún no empieza: no se puede marcar como cumplido.');
        }
        $turno->update(['TurnoProgramadoEstado' => 'CUMPLIDO']);

        return $turno->fresh();
    }

    /**
     * La guardia la realiza solo el personal nombrado y contratado bajo el D.L. 276 y el SERUMS (RIT, Art. 20).
     * Devuelve el motivo de rechazo, o null si el vinculo puede hacer guardia.
     */
    public function motivoSiNoPuedeHacerGuardia(VinculoLaboral $vinculo): ?string
    {
        $vinculo->loadMissing(['regimenLaboral', 'condicionLaboral']);
        $habilitado = $vinculo->regimenLaboral?->RegimenLaboralCodigo === 'DL276'
            || in_array($vinculo->condicionLaboral?->CondicionLaboralCodigo, ['SERUMS_REM', 'SERUMS_EQUIV'], true);

        return $habilitado ? null : 'La guardia la realiza solo el personal nombrado o contratado bajo el D.L. 276 y el SERUMS (RIT, Art. 20).';
    }

    /**
     * Revisa si el trabajador puede tomar un turno [$entrada, $salida] el dia $fecha sin chocar con los demas que ya
     * tiene (de cualquiera de sus vinculos). Devuelve el motivo del conflicto, o null si la agenda queda bien.
     *
     * @param  list<int>  $excluirTurnoIds  turnos que se van a mover o reemplazar y por tanto no cuentan
     */
    public function conflictoDeAgenda(int $trabajadorId, string $fecha, string $entrada, string $salida, array $excluirTurnoIds = []): ?string
    {
        $inicio = CarbonImmutable::parse("{$fecha} {$entrada}");
        $fin = $inicio->addMinutes(TurnoProgramado::minutosEntre($entrada, $salida));

        $otros = TurnoProgramado::query()->with('turno')
            ->whereHas('programacionTrabajador.vinculoLaboral', fn ($v) => $v->where('TrabajadorId', $trabajadorId))
            ->where('TurnoProgramadoEstado', '<>', 'ANULADO')
            ->whereDate('TurnoProgramadoFecha', '>=', $inicio->subDay()->toDateString())
            ->whereDate('TurnoProgramadoFecha', '<=', $inicio->addDay()->toDateString())
            ->when($excluirTurnoIds !== [], fn ($q) => $q->whereNotIn('TurnoProgramadoId', $excluirTurnoIds))
            ->get();

        foreach ($otros as $otro) {
            if ($otro->inicio()->lt($fin) && $inicio->lt($otro->fin())) {
                return 'Se superpone con el turno '.($otro->turno?->TurnoCodigo ?? '').' del '.$otro->inicio()->format('d/m/Y')
                    .' ('.substr($otro->horaEntradaEfectiva(), 0, 5).'-'.substr($otro->horaSalidaEfectiva(), 0, 5).') del mismo trabajador.';
            }
        }

        // Turnos que se tocan (uno termina cuando empieza el otro) forman una cadena de trabajo continuo.
        $desde = $inicio;
        $hasta = $fin;
        do {
            $crecio = false;
            foreach ($otros as $otro) {
                if ($otro->fin()->eq($desde)) {
                    $desde = $otro->inicio();
                    $crecio = true;
                } elseif ($otro->inicio()->eq($hasta)) {
                    $hasta = $otro->fin();
                    $crecio = true;
                }
            }
        } while ($crecio && $desde->diffInMinutes($hasta) < self::MINUTOS_CONTINUOS_PROHIBIDOS);

        if ($desde->diffInMinutes($hasta) >= self::MINUTOS_CONTINUOS_PROHIBIDOS) {
            return 'Con ese turno el trabajador acumularía 24 horas continuas de trabajo, prohibidas por el RIT (Art. 20).';
        }

        return null;
    }
}
