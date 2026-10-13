<?php

namespace App\Models\Organizacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Microred extends Model
{
    protected $table = 'Organizacion.Microred';   // esquema.tabla

    protected $primaryKey = 'MicroredId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;                    // la tabla no tiene created_at/updated_at

    protected $fillable = [
        'MicroredCodigo',
        'MicroredNombre',
        'MicroredDistrito',
        'MicroredUbigeo',
        'MicroredDireccion',
        'MicroredTelefono',
        'MicroredDescripcion',
        'MicroredEstado',
    ];

    protected $casts = [
        'MicroredEstado' => 'boolean',
    ];

    public function establecimientos(): HasMany
    {
        return $this->hasMany(EstablecimientoSalud::class, 'MicroredId', 'MicroredId');
    }
}
