<?php

namespace App\Models\Consolidacion;

use Illuminate\Database\Eloquent\Model;

class PeriodoAsistencia extends Model
{
    protected $table = 'Consolidacion.PeriodoAsistencia';

    protected $primaryKey = 'PeriodoAsistenciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // PeriodoAsistenciaFechaCierre no va aqui: la asigna el proceso de cierre
    // (un Service futuro), no el cliente via create/update directo.
    protected $fillable = [
        'PeriodoAsistenciaAnio',
        'PeriodoAsistenciaMes',
        'PeriodoAsistenciaFechaInicio',
        'PeriodoAsistenciaFechaFin',
        'PeriodoAsistenciaEstado',
    ];

    protected $casts = [
        'PeriodoAsistenciaFechaInicio' => 'date',
        'PeriodoAsistenciaFechaFin'    => 'date',
        'PeriodoAsistenciaFechaCierre' => 'datetime',
    ];

    public function scopeAbiertos($query)
    {
        return $query->where('PeriodoAsistenciaEstado', 'ABIERTO');
    }
}
