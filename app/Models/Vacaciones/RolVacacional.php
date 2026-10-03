<?php

namespace App\Models\Vacaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Programación vacacional (Vacaciones.RolVacacional). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class RolVacacional extends Model
{
    protected $table = 'Vacaciones.RolVacacional';

    protected $primaryKey = 'RolVacacionalId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'PeriodoVacacionalId',
        'RolVacacionalFechaProgramada',
        'RolVacacionalFechaFinProgramada',
        'RolVacacionalDias',
        'RolVacacionalEstado',
    ];

    protected $casts = [
        'RolVacacionalId' => 'integer',
        'PeriodoVacacionalId' => 'integer',
        'RolVacacionalFechaProgramada' => 'date',
        'RolVacacionalFechaFinProgramada' => 'date',
        'RolVacacionalDias' => 'float',
    ];

    public function periodoVacacional(): BelongsTo
    {
        return $this->belongsTo(PeriodoVacacional::class, 'PeriodoVacacionalId', 'PeriodoVacacionalId');
    }

    public function goces(): HasMany
    {
        return $this->hasMany(GoceVacacional::class, 'RolVacacionalId', 'RolVacacionalId');
    }
}
