<?php

namespace App\Models\Asistencia;

use Illuminate\Database\Eloquent\Model;

class EstadoAsistencia extends Model
{
    protected $table = 'Asistencia.EstadoAsistencia';
    protected $primaryKey = 'EstadoAsistenciaId';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'EstadoAsistenciaCodigo',
        'EstadoAsistenciaNombre',
        'EstadoAsistenciaDescripcion',
        'EstadoAsistenciaEsFalta',
        'EstadoAsistenciaEsDescontable',
        'EstadoAsistenciaEsLaborable',
        'EstadoAsistenciaEstado',
    ];

    protected $casts = [
        'EstadoAsistenciaEsFalta' => 'boolean',
        'EstadoAsistenciaEsDescontable' => 'boolean',
        'EstadoAsistenciaEsLaborable' => 'boolean',
        'EstadoAsistenciaEstado' => 'boolean',
    ];
}