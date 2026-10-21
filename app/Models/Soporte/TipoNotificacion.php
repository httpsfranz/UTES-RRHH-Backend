<?php

namespace App\Models\Soporte;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de notificacion y su regla de envio (Soporte.TipoNotificacion, V005): a que roles llega ademas del interesado, en
 * que ambito (establecimiento, microred o toda la red) y si tambien se envia por correo.
 */
class TipoNotificacion extends Model
{
    protected $table = 'Soporte.TipoNotificacion';

    protected $primaryKey = 'TipoNotificacionId';

    public $timestamps = false;

    protected $fillable = [
        'TipoNotificacionCodigo',
        'TipoNotificacionNombre',
        'TipoNotificacionDescripcion',
        'TipoNotificacionRolesDestino',
        'TipoNotificacionAlcance',
        'TipoNotificacionAlInteresado',
        'TipoNotificacionEnviaCorreo',
        'TipoNotificacionEstado',
    ];

    protected $casts = [
        'TipoNotificacionId' => 'integer',
        'TipoNotificacionAlInteresado' => 'boolean',
        'TipoNotificacionEnviaCorreo' => 'boolean',
        'TipoNotificacionEstado' => 'boolean',
    ];

    /** @return list<string> codigos de rol que reciben el aviso */
    public function roles(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->TipoNotificacionRolesDestino))));
    }
}
