<?php

namespace App\Models\Programacion;

use Illuminate\Database\Eloquent\Model;

class TipoPeriodoProgramacion extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Programacion].[TipoPeriodoProgramacion]
    protected $table = 'Programacion.TipoPeriodoProgramacion';

    protected $primaryKey = 'TipoPeriodoProgramacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoPeriodoProgramacionCodigo',
        'TipoPeriodoProgramacionNombre',
        'TipoPeriodoProgramacionDias',
        'TipoPeriodoProgramacionDescripcion',
        'TipoPeriodoProgramacionEstado',
    ];

    protected $casts = [
        'TipoPeriodoProgramacionDias'   => 'integer',
        'TipoPeriodoProgramacionEstado' => 'boolean', // BIT -> true/false
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoPeriodoProgramacionEstado', 1);
    }
}
