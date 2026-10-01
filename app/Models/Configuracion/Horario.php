<?php

namespace App\Models\Configuracion;

use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Horario extends Model
{
    protected $table = 'Configuracion.Horario';

    protected $primaryKey = 'HorarioId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TipoJornadaId',
        'EessId',
        'HorarioCodigo',
        'HorarioNombre',
        'HorarioDescripcion',
        'HorarioEsRotativo',
        'HorarioEstado',
    ];

    protected $casts = [
        'TipoJornadaId' => 'integer',
        'EessId' => 'integer',
        'HorarioEsRotativo' => 'boolean',
        'HorarioEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('HorarioEstado', 1);
    }

    public function tipoJornada(): BelongsTo
    {
        return $this->belongsTo(TipoJornada::class, 'TipoJornadaId', 'TipoJornadaId');
    }

    // EessId NULL = horario institucional de toda la Red.
    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }
}
