<?php

namespace App\Models\Vacaciones;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Goce vacacional (Vacaciones.GoceVacacional). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class GoceVacacional extends Model
{
    protected $table = 'Vacaciones.GoceVacacional';

    protected $primaryKey = 'GoceVacacionalId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'RolVacacionalId',
        'DocumentoSustentoId',
        'GoceVacacionalFechaInicio',
        'GoceVacacionalFechaFin',
        'GoceVacacionalDias',
        'GoceVacacionalEstado',
    ];

    protected $casts = [
        'GoceVacacionalId' => 'integer',
        'RolVacacionalId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'GoceVacacionalFechaInicio' => 'date',
        'GoceVacacionalFechaFin' => 'date',
        'GoceVacacionalDias' => 'float',
    ];

    public function rolVacacional(): BelongsTo
    {
        return $this->belongsTo(RolVacacional::class, 'RolVacacionalId', 'RolVacacionalId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
