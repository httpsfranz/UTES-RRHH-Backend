<?php

namespace App\Models\Biometria;

use App\Models\Personal\Trabajador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorizacion excepcional para marcar con un metodo distinto del reconocimiento facial. El RIT
 * (Art. 21) fija el reconocimiento facial como unica forma de registro; cualquier otro metodo lo
 * autoriza la Unidad de Recursos Humanos, y esa autorizacion es esta fila (con su vigencia).
 */
class AutorizacionMetodo extends Model
{
    protected $table = 'Biometria.AutorizacionMetodo';

    protected $primaryKey = 'AutorizacionMetodoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TrabajadorId',
        'MetodoMarcacionId',
        'AutorizacionMetodoFechaInicio',
        'AutorizacionMetodoFechaFin',
        'AutorizacionMetodoEstado',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'MetodoMarcacionId' => 'integer',
        'AutorizacionMetodoFechaInicio' => 'date',
        'AutorizacionMetodoFechaFin' => 'date',
        'AutorizacionMetodoEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('AutorizacionMetodoEstado', 1);
    }

    /** Autorizaciones que rigen hoy: activas, ya iniciadas y no vencidas. */
    public function scopeVigentes($query)
    {
        return $query->where('AutorizacionMetodoEstado', 1)
            ->whereDate('AutorizacionMetodoFechaInicio', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('AutorizacionMetodoFechaFin')
                ->orWhereDate('AutorizacionMetodoFechaFin', '>=', now()->toDateString()));
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function metodo(): BelongsTo
    {
        return $this->belongsTo(MetodoMarcacion::class, 'MetodoMarcacionId', 'MetodoMarcacionId');
    }

    public function estaVigente(): bool
    {
        $hoy = now()->startOfDay();

        return $this->AutorizacionMetodoEstado
            && $this->AutorizacionMetodoFechaInicio->lte($hoy)
            && ($this->AutorizacionMetodoFechaFin === null || $this->AutorizacionMetodoFechaFin->gte($hoy));
    }
}
