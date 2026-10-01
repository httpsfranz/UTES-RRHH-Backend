<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turno extends Model
{
    protected $table = 'Configuracion.Turno';

    protected $primaryKey = 'TurnoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // TurnoCruzaMedianoche y TurnoDuracionMinutos son columnas calculadas y persistidas:
    // la base las deriva de entrada/salida, nunca se escriben desde Laravel.
    protected $fillable = [
        'TipoJornadaId',
        'TablaToleranciaId',
        'TurnoCodigo',
        'TurnoNombre',
        'TurnoHoraEntrada',
        'TurnoHoraSalida',
        'TurnoToleranciaEntradaMinutos',
        'TurnoToleranciaSalidaMinutos',
        'TurnoRefrigerioMinutos',
        'TurnoPermiteHoraExtra',
        'TurnoEsGuardia',
        'TurnoEstado',
    ];

    protected $casts = [
        'TipoJornadaId' => 'integer',
        'TablaToleranciaId' => 'integer',
        'TurnoCruzaMedianoche' => 'boolean',
        'TurnoDuracionMinutos' => 'integer',
        'TurnoToleranciaEntradaMinutos' => 'integer',
        'TurnoToleranciaSalidaMinutos' => 'integer',
        'TurnoRefrigerioMinutos' => 'integer',
        'TurnoPermiteHoraExtra' => 'boolean',
        'TurnoEsGuardia' => 'boolean',
        'TurnoEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('TurnoEstado', 1);
    }

    public function tipoJornada(): BelongsTo
    {
        return $this->belongsTo(TipoJornada::class, 'TipoJornadaId', 'TipoJornadaId');
    }

    // TablaToleranciaId es nullable: sin tabla propia el turno usa la escala general.
    public function tablaTolerancia(): BelongsTo
    {
        return $this->belongsTo(TablaTolerancia::class, 'TablaToleranciaId', 'TablaToleranciaId');
    }
}
