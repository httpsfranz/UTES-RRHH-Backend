<?php

namespace App\Models\Biometria;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biometria.ContingenciaMarcacion (V007). Sus reglas viven en App\Support\RegistrosRit. */
class ContingenciaMarcacion extends Model
{
    protected $table = 'Biometria.ContingenciaMarcacion';

    protected $primaryKey = 'ContingenciaMarcacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'UsuarioId',
        'DocumentoSustentoId',
        'ContingenciaMarcacionFechaInicio',
        'ContingenciaMarcacionFechaFin',
        'ContingenciaMarcacionMotivo',
        'ContingenciaMarcacionEstado',
    ];

    protected $casts = [
        'ContingenciaMarcacionFechaInicio' => 'date',
        'ContingenciaMarcacionFechaFin' => 'date',
        'ContingenciaMarcacionEstado' => 'boolean',
    ];

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
