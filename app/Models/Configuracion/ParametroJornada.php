<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParametroJornada extends Model
{
    protected $table = 'Configuracion.ParametroJornada';

    protected $primaryKey = 'ParametroJornadaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TipoJornadaId',
        'ParametroJornadaVigenciaDesde',
        'ParametroJornadaVigenciaHasta',
        'ParametroJornadaHorasDiarias',
        'ParametroJornadaHorasSemanales',
        'ParametroJornadaHorasMensuales',
        'ParametroJornadaEstado',
    ];

    protected $casts = [
        'TipoJornadaId' => 'integer',
        'ParametroJornadaVigenciaDesde' => 'date',
        'ParametroJornadaVigenciaHasta' => 'date',
        'ParametroJornadaHorasDiarias' => 'float',
        'ParametroJornadaHorasSemanales' => 'float',
        'ParametroJornadaHorasMensuales' => 'float',
        'ParametroJornadaEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('ParametroJornadaEstado', 1);
    }

    public function tipoJornada(): BelongsTo
    {
        return $this->belongsTo(TipoJornada::class, 'TipoJornadaId', 'TipoJornadaId');
    }
}
