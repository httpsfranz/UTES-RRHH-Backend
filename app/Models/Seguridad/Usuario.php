<?php

namespace App\Models\Seguridad;

use App\Models\Personal\Trabajador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta de acceso de un trabajador (1 a 1). El hash de la contrasena nunca sale por la API.
 * No es Laravel\\User: el esquema usa Seguridad.Usuario, no la tabla `users` de Laravel.
 */
class Usuario extends Model
{
    protected $table = 'Seguridad.Usuario';

    protected $primaryKey = 'UsuarioId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $hidden = ['UsuarioPasswordHash'];

    // UsuarioFechaCreacion la asigna la base (DEFAULT SYSDATETIME()).
    protected $fillable = [
        'TrabajadorId',
        'UsuarioNombre',
        'UsuarioPasswordHash',
        'UsuarioCorreo',
        'UsuarioEstado',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'UsuarioFechaCreacion' => 'datetime',
        'UsuarioEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('UsuarioEstado', 1);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }
}
