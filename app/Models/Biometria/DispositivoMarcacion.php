<?php

namespace App\Models\Biometria;

use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositivoMarcacion extends Model
{
    protected $table = 'Biometria.DispositivoMarcacion';

    protected $primaryKey = 'DispositivoMarcacionId';

    public $incrementing = true;

    protected $keyType = 'int';

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

    // EessId es nullable: un dispositivo puede no estar asignado a un establecimiento.
    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }
}
