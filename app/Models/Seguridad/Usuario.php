<?php

namespace App\Models\Seguridad;

use App\Models\Personal\Trabajador;
use App\Support\AccesoUsuario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cuenta de acceso de un trabajador (1 a 1). El hash de la contrasena nunca sale por la API.
 * Es el usuario autenticado de la API (tokens Sanctum); usa Seguridad.Usuario y no la tabla `users` de Laravel.
 */
class Usuario extends Authenticatable
{
    use HasApiTokens;

    /** Accesos del usuario en esta peticion (los calcula AccesoUsuario::de; no es una columna). */
    public ?AccesoUsuario $accesoCalculado = null;

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
        'UsuarioBloqueado',
        'UsuarioBloqueadoHasta',
        'UsuarioMotivoBloqueo',
        'UsuarioIntentosFallidos',
        'UsuarioDebeCambiarPassword',
        'UsuarioFechaCambioPassword',
        'UsuarioUltimoAcceso',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'UsuarioFechaCreacion' => 'datetime',
        'UsuarioEstado' => 'boolean',
        'UsuarioBloqueado' => 'boolean',
        'UsuarioBloqueadoHasta' => 'datetime',
        'UsuarioDebeCambiarPassword' => 'boolean',
        'UsuarioFechaCambioPassword' => 'datetime',
        'UsuarioUltimoAcceso' => 'datetime',
        'UsuarioIntentosFallidos' => 'integer',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->UsuarioPasswordHash;
    }

    public function scopeActivos($query)
    {
        return $query->where('UsuarioEstado', 1);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function usuarioRoles(): HasMany
    {
        return $this->hasMany(UsuarioRol::class, 'UsuarioId', 'UsuarioId');
    }

    public function ambitos(): HasMany
    {
        return $this->hasMany(UsuarioAmbito::class, 'UsuarioId', 'UsuarioId');
    }
}
