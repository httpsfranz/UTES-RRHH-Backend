<?php

namespace App\Models\Personal;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Personal.DesplazamientoTemporal (V007). Sus reglas viven en App\Support\RegistrosRit. */
class DesplazamientoTemporal extends Model
{
    protected $table = 'Personal.DesplazamientoTemporal';

    protected $primaryKey = 'DesplazamientoTemporalId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'EessDestinoId',
        'DocumentoSustentoId',
        'DesplazamientoTemporalTipo',
        'DesplazamientoTemporalFechaInicio',
        'DesplazamientoTemporalFechaFin',
        'DesplazamientoTemporalMotivo',
        'DesplazamientoTemporalEstado',
    ];

    protected $casts = [
        'DesplazamientoTemporalFechaInicio' => 'date',
        'DesplazamientoTemporalFechaFin' => 'date',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessDestinoId', 'EessId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
