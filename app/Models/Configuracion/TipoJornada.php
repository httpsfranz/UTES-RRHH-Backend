<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;

class TipoJornada extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Configuracion].[TipoJornada]
    protected $table = 'Configuracion.TipoJornada';

    protected $primaryKey = 'TipoJornadaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoJornadaCodigo',
        'TipoJornadaNombre',
        'TipoJornadaDescripcion',
        'TipoJornadaEstado',
    ];

    protected $casts = [
        'TipoJornadaEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoJornadaEstado', 1);
    }
}
