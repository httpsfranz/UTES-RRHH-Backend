<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sesión de acceso (Seguridad.SesionAcceso). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class SesionAcceso extends Model
{
    protected $table = 'Seguridad.SesionAcceso';

    protected $primaryKey = 'SesionAccesoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'UsuarioId',
        'SesionAccesoFechaInicio',
        'SesionAccesoFechaFin',
        'SesionAccesoDireccionIp',
        'SesionAccesoResultado',
    ];

    protected $casts = [
        'SesionAccesoId' => 'integer',
        'UsuarioId' => 'integer',
        'SesionAccesoFechaInicio' => 'datetime',
        'SesionAccesoFechaFin' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }
}
