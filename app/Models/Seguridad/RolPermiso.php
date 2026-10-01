<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla puente Rol <-> Permiso. A diferencia del resto de catalogos, borrar la fila SI es
 * la operacion correcta (quitar un permiso a un rol), ademas de poder dejarla inactiva.
 */
class RolPermiso extends Model
{
    protected $table = 'Seguridad.RolPermiso';

    protected $primaryKey = 'RolPermisoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'RolId',
        'PermisoId',
        'RolPermisoEstado',
    ];

    protected $casts = [
        'RolId' => 'integer',
        'PermisoId' => 'integer',
        'RolPermisoEstado' => 'boolean',
    ];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'RolId', 'RolId');
    }

    public function permiso(): BelongsTo
    {
        return $this->belongsTo(Permiso::class, 'PermisoId', 'PermisoId');
    }
}
