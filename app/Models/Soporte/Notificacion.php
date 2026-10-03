<?php

namespace App\Models\Soporte;

use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notificación (Soporte.Notificacion). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class Notificacion extends Model
{
    protected $table = 'Soporte.Notificacion';

    protected $primaryKey = 'NotificacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'UsuarioId',
        'NotificacionTipo',
        'NotificacionTitulo',
        'NotificacionMensaje',
        'NotificacionEnlace',
        'NotificacionFecha',
        'NotificacionLeida',
    ];

    protected $casts = [
        'NotificacionId' => 'integer',
        'UsuarioId' => 'integer',
        'NotificacionFecha' => 'datetime',
        'NotificacionLeida' => 'boolean',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }
}
