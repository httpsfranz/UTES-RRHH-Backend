<?php

namespace App\Models\Programacion;

use App\Models\Personal\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Programación de un trabajador dentro de un período (Programacion.ProgramacionTrabajador). Las reglas viven en el Form Request y el Service. */
class ProgramacionTrabajador extends Model
{
    protected $table = 'Programacion.ProgramacionTrabajador';

    protected $primaryKey = 'ProgramacionTrabajadorId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ProgramacionPeriodoId',
        'VinculoLaboralId',
        'ProgramacionTrabajadorHorasProgramadas',
        'ProgramacionTrabajadorObservacion',
        'ProgramacionTrabajadorEstado',
    ];

    protected $casts = [
        'ProgramacionTrabajadorId' => 'integer',
        'ProgramacionPeriodoId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'ProgramacionTrabajadorHorasProgramadas' => 'float',
    ];

    /** Horas programadas = suma de la duración de sus turnos vigentes (los anulados no cuentan). No se escribe a mano. */
    public function recalcularHoras(): void
    {
        $minutos = $this->turnos()->with('turno')->where('TurnoProgramadoEstado', '<>', 'ANULADO')->get()
            ->sum(fn (TurnoProgramado $turno) => $turno->minutosEfectivos());

        static::query()->whereKey($this->getKey())->update(['ProgramacionTrabajadorHorasProgramadas' => round($minutos / 60, 2)]);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(ProgramacionPeriodo::class, 'ProgramacionPeriodoId', 'ProgramacionPeriodoId');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(TurnoProgramado::class, 'ProgramacionTrabajadorId', 'ProgramacionTrabajadorId');
    }
}
