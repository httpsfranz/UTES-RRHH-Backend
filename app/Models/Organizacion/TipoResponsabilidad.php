<?php

namespace App\Models\Organizacion;

use Illuminate\Database\Eloquent\Model;

class TipoResponsabilidad extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Organizacion].[TipoResponsabilidad]
    protected $table = 'Organizacion.TipoResponsabilidad';

    protected $primaryKey = 'TipoResponsabilidadId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoResponsabilidadCodigo',
        'TipoResponsabilidadNombre',
        'TipoResponsabilidadDescripcion',
        'TipoResponsabilidadEstado',
    ];

    protected $casts = [
        'TipoResponsabilidadEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoResponsabilidadEstado', 1);
    }
}
