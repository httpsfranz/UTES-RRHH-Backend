<?php

namespace App\Models\Organizacion;

use Illuminate\Database\Eloquent\Model;

class TipoEstablecimiento extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Organizacion].[TipoEstablecimiento]
    protected $table = 'Organizacion.TipoEstablecimiento';

    protected $primaryKey = 'TipoEstablecimientoId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoEstablecimientoCodigo',
        'TipoEstablecimientoNombre',
        'TipoEstablecimientoDescripcion',
        'TipoEstablecimientoEstado',
    ];

    protected $casts = [
        'TipoEstablecimientoEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoEstablecimientoEstado', 1);
    }
}
