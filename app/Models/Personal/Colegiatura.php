<?php

namespace App\Models\Personal;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colegiatura profesional de un trabajador. La colegiatura es del trabajador, no del cargo.
 * RIT (obligacion 37): los profesionales deben tener colegiatura y habilitacion vigentes
 * durante toda su relacion laboral.
 */
class Colegiatura extends Model
{
    protected $table = 'Personal.Colegiatura';

    protected $primaryKey = 'ColegiaturaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TrabajadorId',
        'ColegiaturaTipoId',
        'DocumentoSustentoId',
        'ColegiaturaNumero',
        'ColegiaturaFechaColegiatura',
        'ColegiaturaFechaHabilitacion',
        'ColegiaturaFechaVencimiento',
        'ColegiaturaEsHabilitado',
        'ColegiaturaEsPrincipal',
        'ColegiaturaObservacion',
        'ColegiaturaEstado',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'ColegiaturaTipoId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'ColegiaturaFechaColegiatura' => 'date',
        'ColegiaturaFechaHabilitacion' => 'date',
        'ColegiaturaFechaVencimiento' => 'date',
        'ColegiaturaEsHabilitado' => 'boolean',
        'ColegiaturaEsPrincipal' => 'boolean',
        'ColegiaturaEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('ColegiaturaEstado', 1);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(ColegiaturaTipo::class, 'ColegiaturaTipoId', 'ColegiaturaTipoId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    /** Vencida: tiene fecha de vencimiento y ya paso. */
    public function estaVencida(): bool
    {
        return $this->ColegiaturaFechaVencimiento !== null && $this->ColegiaturaFechaVencimiento->lt(now()->startOfDay());
    }

    /** Vigente: registro activo, habilitado por el colegio y no vencido. */
    public function estaVigente(): bool
    {
        return $this->ColegiaturaEstado && $this->ColegiaturaEsHabilitado && ! $this->estaVencida();
    }
}
