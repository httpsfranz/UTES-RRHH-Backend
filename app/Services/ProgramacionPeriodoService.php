<?php

namespace App\Services;

use App\Models\Programacion\ProgramacionPeriodo;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Models\Programacion\TurnoProgramado;
use App\Support\HoraLocal;
use App\Support\ReglasDeSolicitud;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de la programacion de un periodo: BORRADOR -> PUBLICADA -> CERRADA, o ANULADA mientras no este cerrada.
 * RIT, Art. 16: una vez remitida la programacion, queda prohibida cualquier modificacion de los turnos.
 *
 * El estado del periodo se propaga a la programacion de sus trabajadores (y, al anular, a sus turnos): asi el detalle
 * nunca queda en un estado distinto del de su cabecera.
 */
class ProgramacionPeriodoService
{
    /** Por que la programacion ya no se edita directamente (null = sigue en borrador y se puede modificar). */
    public static function motivoDeBloqueo(ProgramacionPeriodo $periodo): ?string
    {
        $estado = match ($periodo->ProgramacionPeriodoEstado) {
            'BORRADOR' => null,
            'PUBLICADA' => 'publicada',
            'CERRADA' => 'cerrada',
            default => 'anulada',
        };

        return $estado === null ? null : "La programación está {$estado} y ya no se puede modificar (RIT, Art. 16)."
            .($estado === 'publicada' ? ' Los cambios posteriores se tramitan como cambio de turno.' : '');
    }

    /** @throws DomainException si la programacion ya no es un borrador. */
    public static function exigirBorrador(ProgramacionPeriodo $periodo): void
    {
        if ($mensaje = self::motivoDeBloqueo($periodo)) {
            throw new DomainException($mensaje);
        }
    }

    /** Dia del mes anterior hasta el que la programacion se sube a TUA-SUSALUD: ultimo hito del Art. 16. */
    public const DIA_LIMITE_PUBLICACION = 20;

    /** Reprogramaciones permitidas por mes y dia limite (RIT, Art. 16). */
    public const MAXIMO_REPROGRAMACIONES = 2;

    /** Fecha limite para publicar la programacion: el dia 20 del mes anterior al que programa. */
    public static function fechaLimite(ProgramacionPeriodo $programacion): CarbonImmutable
    {
        return CarbonImmutable::parse($programacion->ProgramacionPeriodoFechaInicio)->startOfMonth()->subMonth()->setDay(self::DIA_LIMITE_PUBLICACION);
    }

    public function publicar(ProgramacionPeriodo $programacion, ?string $motivoTardio = null): ProgramacionPeriodo
    {
        if (ReglasDeSolicitud::controlDePlazos() && $programacion->ProgramacionPeriodoEstado === 'BORRADOR' && HoraLocal::hoy()->gt(self::fechaLimite($programacion)) && blank($motivoTardio) && blank($programacion->ProgramacionPeriodoMotivoTardio)) {
            throw new DomainException('La programación debió publicarse hasta el '.self::fechaLimite($programacion)->format('d/m/Y').' (RIT, Art. 16): indica el motivo del retraso.');
        }
        if ($programacion->ProgramacionPeriodoEstado !== 'BORRADOR') {
            throw new DomainException('Solo se puede publicar una programación en borrador.');
        }

        return DB::transaction(function () use ($programacion, $motivoTardio) {
            $programacion->update(['ProgramacionPeriodoEstado' => 'PUBLICADA', 'ProgramacionPeriodoFechaPublicacion' => now()] + (blank($motivoTardio) ? [] : ['ProgramacionPeriodoMotivoTardio' => mb_substr($motivoTardio, 0, 500)]));
            $this->trabajadores($programacion)->where('ProgramacionTrabajadorEstado', 'BORRADOR')->update(['ProgramacionTrabajadorEstado' => 'PUBLICADA']);

            return $programacion->fresh();
        });
    }

    /**
     * Devuelve una programacion publicada a borrador para corregirla: hasta dos veces y hasta el dia 20 del mes
     * programado, salvo caso excepcional sustentado (RIT, Art. 16).
     */
    public function reprogramar(ProgramacionPeriodo $programacion, string $motivo, bool $excepcional = false): ProgramacionPeriodo
    {
        if ($programacion->ProgramacionPeriodoEstado !== 'PUBLICADA') {
            throw new DomainException('Solo se reprograma una programación publicada.');
        }
        if ($programacion->ProgramacionPeriodoReprogramaciones >= self::MAXIMO_REPROGRAMACIONES) {
            throw new DomainException('Se permiten hasta dos reprogramaciones (RIT, Art. 16). Los demás cambios se tramitan como cambio de turno.');
        }
        $limite = CarbonImmutable::parse($programacion->ProgramacionPeriodoFechaInicio)->setDay(self::DIA_LIMITE_PUBLICACION);
        if (ReglasDeSolicitud::controlDePlazos() && ! $excepcional && HoraLocal::hoy()->gt($limite)) {
            throw new DomainException('El plazo para reprogramar venció el '.$limite->format('d/m/Y').' (RIT, Art. 16): solo procede por caso excepcional sustentado.');
        }

        return DB::transaction(function () use ($programacion, $motivo) {
            $programacion->update([
                'ProgramacionPeriodoEstado' => 'BORRADOR',
                'ProgramacionPeriodoReprogramaciones' => $programacion->ProgramacionPeriodoReprogramaciones + 1,
                'ProgramacionPeriodoObservacion' => mb_substr(trim($programacion->ProgramacionPeriodoObservacion."\nReprogramación: {$motivo}"), 0, 1000),
            ]);
            $this->trabajadores($programacion)->where('ProgramacionTrabajadorEstado', 'PUBLICADA')->update(['ProgramacionTrabajadorEstado' => 'BORRADOR']);

            return $programacion->fresh();
        });
    }

    public function cerrar(ProgramacionPeriodo $programacion): ProgramacionPeriodo
    {
        if ($programacion->ProgramacionPeriodoEstado !== 'PUBLICADA') {
            throw new DomainException('Solo se puede cerrar una programación publicada.');
        }

        return DB::transaction(function () use ($programacion) {
            $programacion->update(['ProgramacionPeriodoEstado' => 'CERRADA']);
            $this->trabajadores($programacion)->where('ProgramacionTrabajadorEstado', 'PUBLICADA')->update(['ProgramacionTrabajadorEstado' => 'CERRADA']);

            return $programacion->fresh();
        });
    }

    /** Anular es idempotente; una programacion cerrada ya sirvio para la asistencia y no se anula. */
    public function anular(ProgramacionPeriodo $programacion): ProgramacionPeriodo
    {
        if ($programacion->ProgramacionPeriodoEstado === 'CERRADA') {
            throw new DomainException('No se puede anular una programación cerrada.');
        }
        if ($programacion->ProgramacionPeriodoEstado === 'ANULADA') {
            return $programacion->fresh();
        }

        return DB::transaction(function () use ($programacion) {
            $programacion->update(['ProgramacionPeriodoEstado' => 'ANULADA']);
            $ids = $this->trabajadores($programacion)->pluck('ProgramacionTrabajadorId');
            // Los turnos que aun no se cumplieron quedan sin efecto; los CUMPLIDOS son historia y se conservan.
            TurnoProgramado::query()->whereIn('ProgramacionTrabajadorId', $ids)->whereIn('TurnoProgramadoEstado', ['PROGRAMADO', 'REPROGRAMADO'])
                ->update(['TurnoProgramadoEstado' => 'ANULADO']);
            $this->trabajadores($programacion)->update(['ProgramacionTrabajadorEstado' => 'ANULADA']);
            ProgramacionTrabajador::query()->whereIn('ProgramacionTrabajadorId', $ids)->get()->each->recalcularHoras();

            return $programacion->fresh();
        });
    }

    private function trabajadores(ProgramacionPeriodo $programacion)
    {
        return ProgramacionTrabajador::query()->where('ProgramacionPeriodoId', $programacion->ProgramacionPeriodoId);
    }
}
