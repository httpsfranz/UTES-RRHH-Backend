<?php

namespace App\Models\Soporte;

use Illuminate\Database\Eloquent\Model;

class LogIntegracion extends Model
{
    protected $table = 'Soporte.LogIntegracion';

    protected $primaryKey = 'LogIntegracionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // Sin $fillable: lo escriben los procesos de integracion internamente,
    // no un cliente HTTP (ver LogIntegracionController, solo lectura).
    protected $casts = [
        'LogIntegracionFechaHora'  => 'datetime',
        'LogIntegracionReintentos' => 'integer',
    ];
}
