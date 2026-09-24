<?php

namespace App\Models\Programacion;

use Illuminate\Database\Eloquent\Model;

class TipoCambioTurno extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Programacion].[TipoCambioTurno]
    protected $table = 'Programacion.TipoCambioTurno';

    protected $primaryKey = 'TipoCambioTurnoId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoCambioTurnoCodigo',
        'TipoCambioTurnoNombre',
        'TipoCambioTurnoRequiereReemplazante',
        'TipoCambioTurnoDescripcion',
        'TipoCambioTurnoEstado',
    ];

    protected $casts = [
        'TipoCambioTurnoRequiereReemplazante' => 'boolean', // BIT -> true/false
        'TipoCambioTurnoEstado'               => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoCambioTurnoEstado', 1);
    }
}
