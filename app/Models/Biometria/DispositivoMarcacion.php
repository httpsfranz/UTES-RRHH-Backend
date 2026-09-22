<?php

namespace App\Models\Biometria;

use Illuminate\Database\Eloquent\Model;

class DispositivoMarcacion extends Model
{
    protected $table = 'Biometria.DispositivoMarcacion';

    protected $primaryKey = 'DispositivoMarcacionId';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'DispositivoMarcacionCodigo',
        'DispositivoMarcacionNombre',
        'DispositivoMarcacionTipo',
        'DispositivoMarcacionUbicacion',
        'DispositivoMarcacionIp',
        'DispositivoMarcacionEstado',
    ];

    protected $casts = [
        'DispositivoMarcacionId' => 'integer',
        'EessId' => 'integer',
        'DispositivoMarcacionEstado' => 'boolean',
    ];
}