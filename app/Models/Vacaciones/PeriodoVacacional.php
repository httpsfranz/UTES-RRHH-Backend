<?php

namespace App\Models\Vacaciones;

use App\Models\Personal\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Período vacacional (Vacaciones.PeriodoVacacional). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class PeriodoVacacional extends Model
{
    protected $table = 'Vacaciones.PeriodoVacacional';

    protected $primaryKey = 'PeriodoVacacionalId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'PeriodoVacacionalAnio',
        'PeriodoVacacionalFechaInicio',
        'PeriodoVacacionalFechaFin',
        'PeriodoVacacionalDiasGanados',
        'PeriodoVacacionalDiasDisponibles',
        'PeriodoVacacionalEstado',
    ];

    protected $casts = [
        'PeriodoVacacionalId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'PeriodoVacacionalAnio' => 'integer',
        'PeriodoVacacionalFechaInicio' => 'date',
        'PeriodoVacacionalFechaFin' => 'date',
        'PeriodoVacacionalDiasGanados' => 'float',
        'PeriodoVacacionalDiasDisponibles' => 'float',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }
}
