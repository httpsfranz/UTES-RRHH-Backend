<?php

namespace App\Models\Programacion;

use App\Models\Configuracion\Turno;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Turno programado (Programacion.TurnoProgramado). Las reglas de negocio viven en el Form Request y el Service; aqui solo el calculo de horas. */
class TurnoProgramado extends Model
{
    protected $table = 'Programacion.TurnoProgramado';

    protected $primaryKey = 'TurnoProgramadoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ProgramacionTrabajadorId',
        'TurnoId',
        'TurnoProgramadoFecha',
        'TurnoProgramadoHoraEntrada',
        'TurnoProgramadoHoraSalida',
        'TurnoProgramadoEsGuardia',
        'TurnoProgramadoObservacion',
        'TurnoProgramadoEstado',
    ];

    protected $casts = [
        'TurnoProgramadoId' => 'integer',
        'ProgramacionTrabajadorId' => 'integer',
        'TurnoId' => 'integer',
        'TurnoProgramadoFecha' => 'date',
        'TurnoProgramadoEsGuardia' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Las horas programadas del trabajador siempre reflejan sus turnos: se recalculan al guardar o quitar uno
        // (y en el trabajador anterior si el turno cambio de manos, p. ej. por una permuta).
        static::saved(function (self $turno) {
            ProgramacionTrabajador::query()->find($turno->ProgramacionTrabajadorId)?->recalcularHoras();
            if ($turno->wasChanged('ProgramacionTrabajadorId')) {
                ProgramacionTrabajador::query()->find($turno->getOriginal('ProgramacionTrabajadorId'))?->recalcularHoras();
            }
        });
        static::deleted(fn (self $turno) => ProgramacionTrabajador::query()->find($turno->ProgramacionTrabajadorId)?->recalcularHoras());
    }

    /** Minutos entre dos horas "HH:MM[:SS]"; si la salida no es posterior a la entrada, el turno cruza la medianoche (como en Turno). */
    public static function minutosEntre(string $entrada, string $salida): int
    {
        $e = self::aMinutos($entrada);
        $s = self::aMinutos($salida);

        return $s > $e ? $s - $e : 1440 - ($e - $s);
    }

    private static function aMinutos(string $hora): int
    {
        return ((int) substr($hora, 0, 2)) * 60 + (int) substr($hora, 3, 2);
    }

    /** "HH:MM:SS" de entrada: la propia del turno programado o, si no la trae, la del Turno. */
    public function horaEntradaEfectiva(): ?string
    {
        $hora = $this->TurnoProgramadoHoraEntrada ?? $this->turno?->TurnoHoraEntrada;

        return $hora === null ? null : substr((string) $hora, 0, 8);
    }

    public function horaSalidaEfectiva(): ?string
    {
        $hora = $this->TurnoProgramadoHoraSalida ?? $this->turno?->TurnoHoraSalida;

        return $hora === null ? null : substr((string) $hora, 0, 8);
    }

    public function minutosEfectivos(): int
    {
        $entrada = $this->horaEntradaEfectiva();
        $salida = $this->horaSalidaEfectiva();

        return $entrada && $salida ? self::minutosEntre($entrada, $salida) : 0;
    }

    /** Instante en que empieza el turno (fecha + hora de entrada). */
    public function inicio(): ?CarbonImmutable
    {
        $entrada = $this->horaEntradaEfectiva();

        return $entrada ? CarbonImmutable::parse($this->TurnoProgramadoFecha->toDateString().' '.$entrada) : null;
    }

    public function fin(): ?CarbonImmutable
    {
        return $this->inicio()?->addMinutes($this->minutosEfectivos());
    }

    public function programacionTrabajador(): BelongsTo
    {
        return $this->belongsTo(ProgramacionTrabajador::class, 'ProgramacionTrabajadorId', 'ProgramacionTrabajadorId');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'TurnoId', 'TurnoId');
    }
}
