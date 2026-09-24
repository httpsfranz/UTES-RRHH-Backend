<?php

namespace App\Models\Compensaciones;

use Illuminate\Database\Eloquent\Model;

class TipoCompensacion extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Compensaciones].[TipoCompensacion]
    protected $table = 'Compensaciones.TipoCompensacion';

    protected $primaryKey = 'TipoCompensacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoCompensacionCodigo',
        'TipoCompensacionNombre',
        'TipoCompensacionDescripcion',
        'TipoCompensacionEstado',
    ];

    protected $casts = [
        'TipoCompensacionEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoCompensacionEstado', 1);
    }
}
