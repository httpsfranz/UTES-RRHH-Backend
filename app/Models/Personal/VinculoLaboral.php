<?php

namespace App\Models\Personal;

use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relacion laboral de un trabajador con la Red. La asistencia cuelga del vinculo, no del trabajador:
 * una misma persona puede tener varios vinculos a lo largo del tiempo (nunca dos activos a la vez).
 */
class VinculoLaboral extends Model
{
    protected $table = 'Personal.VinculoLaboral';

    protected $primaryKey = 'VinculoLaboralId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TrabajadorId',
        'EessId',
        'RegimenLaboralId',
        'CondicionLaboralId',
        'CargoId',
        'VinculoLaboralCodigo',
        'VinculoLaboralCodigoAirhsp',
        'VinculoLaboralNumeroPlaza',
        'VinculoLaboralFechaInicio',
        'VinculoLaboralFechaFin',
        'VinculoLaboralMotivoCese',
        'VinculoLaboralEstado',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'EessId' => 'integer',
        'RegimenLaboralId' => 'integer',
        'CondicionLaboralId' => 'integer',
        'CargoId' => 'integer',
        'VinculoLaboralFechaInicio' => 'date',
        'VinculoLaboralFechaFin' => 'date',
        'VinculoLaboralEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('VinculoLaboralEstado', 1);
    }

    /** Vinculos que rigen hoy: activos, ya iniciados y sin fecha de fin vencida. */
    public function scopeVigentes($query)
    {
        return $query->where('VinculoLaboralEstado', 1)
            ->whereDate('VinculoLaboralFechaInicio', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('VinculoLaboralFechaFin')
                ->orWhereDate('VinculoLaboralFechaFin', '>=', now()->toDateString()));
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function regimenLaboral(): BelongsTo
    {
        return $this->belongsTo(RegimenLaboral::class, 'RegimenLaboralId', 'RegimenLaboralId');
    }

    public function condicionLaboral(): BelongsTo
    {
        return $this->belongsTo(CondicionLaboral::class, 'CondicionLaboralId', 'CondicionLaboralId');
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class, 'CargoId', 'CargoId');
    }
}
