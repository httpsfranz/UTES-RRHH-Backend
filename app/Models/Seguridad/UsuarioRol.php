<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rol de usuario (Seguridad.UsuarioRol). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class UsuarioRol extends Model
{
    protected $table = 'Seguridad.UsuarioRol';

    protected $primaryKey = 'UsuarioRolId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'UsuarioId',
        'RolId',
        'UsuarioRolFechaInicio',
        'UsuarioRolFechaFin',
        'UsuarioRolEstado',
    ];

    protected $casts = [
        'UsuarioRolId' => 'integer',
        'UsuarioId' => 'integer',
        'RolId' => 'integer',
        'UsuarioRolFechaInicio' => 'date',
        'UsuarioRolFechaFin' => 'date',
        'UsuarioRolEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('UsuarioRolEstado', 1);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'RolId', 'RolId');
    }
}
