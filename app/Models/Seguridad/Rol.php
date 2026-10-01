<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Seguridad].[Rol]
    protected $table = 'Seguridad.Rol';

    protected $primaryKey = 'RolId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'RolCodigo',
        'RolNombre',
        'RolDescripcion',
        'RolEstado',
    ];

    protected $casts = [
        'RolEstado' => 'boolean', // BIT -> true/false
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('RolEstado', 1);
    }

    // Permisos asignados (tabla puente Seguridad.RolPermiso).
    public function rolPermisos(): HasMany
    {
        return $this->hasMany(RolPermiso::class, 'RolId', 'RolId');
    }

    // NOTA: Seguridad.UsuarioRol depende de esta tabla, pero su modulo aun no existe (nivel 3).
}
