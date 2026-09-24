<?php

namespace App\Models\Solicitudes;

use Illuminate\Database\Eloquent\Model;

class TipoPapeleta extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Solicitudes].[TipoPapeleta]
    protected $table = 'Solicitudes.TipoPapeleta';

    protected $primaryKey = 'TipoPapeletaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoPapeletaCodigo',
        'TipoPapeletaNombre',
        'TipoPapeletaDescripcion',
        'TipoPapeletaEsDescontable',
        'TipoPapeletaRequiereSustento',
        'TipoPapeletaAfectaJornada',
        'TipoPapeletaEsCompensable',
        'TipoPapeletaEstado',
    ];

    protected $casts = [
        'TipoPapeletaEsDescontable'    => 'boolean', // BIT -> true/false
        'TipoPapeletaRequiereSustento' => 'boolean',
        'TipoPapeletaAfectaJornada'    => 'boolean',
        'TipoPapeletaEsCompensable'    => 'boolean',
        'TipoPapeletaEstado'           => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoPapeletaEstado', 1);
    }
}
