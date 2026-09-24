<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;

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

    // NOTA: Seguridad.RolPermiso y Seguridad.UsuarioRol dependen de esta tabla,
    // pero sus modulos aun no existen (nivel 1+). No se declaran relaciones todavia.
}
