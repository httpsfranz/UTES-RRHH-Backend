<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una celda de la grilla semanal de un horario: que turno se cumple (o descansa) cada dia.
 * Tabla de detalle: sin columna de Estado, se reemplaza/elimina de verdad.
 */
class HorarioDetalle extends Model
{
    protected $table = 'Configuracion.HorarioDetalle';

    protected $primaryKey = 'HorarioDetalleId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'HorarioId',
        'TurnoId',
        'HorarioDetalleDia',
        'HorarioDetalleOrden',
        'HorarioDetalleEsDescanso',
    ];

    protected $casts = [
        'HorarioId' => 'integer',
        'TurnoId' => 'integer',
        'HorarioDetalleDia' => 'integer',
        'HorarioDetalleOrden' => 'integer',
        'HorarioDetalleEsDescanso' => 'boolean',
    ];

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'HorarioId', 'HorarioId');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'TurnoId', 'TurnoId');
    }
}
