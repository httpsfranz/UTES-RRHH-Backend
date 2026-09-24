<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Seguridad].[Permiso]
    protected $table = 'Seguridad.Permiso';

    protected $primaryKey = 'PermisoId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'PermisoCodigo',
        'PermisoNombre',
        'PermisoModulo',
        'PermisoDescripcion',
        'PermisoEstado',
    ];

    protected $casts = [
        'PermisoEstado' => 'boolean', // BIT -> true/false
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('PermisoEstado', 1);
    }
}
