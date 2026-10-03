<?php

namespace App\Models\Asistencia;

use App\Models\Biometria\DispositivoMarcacion;
use App\Models\Biometria\MetodoMarcacion;
use App\Models\Biometria\PlantillaBiometrica;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Marcación (Asistencia.Marcacion). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class Marcacion extends Model
{
    protected $table = 'Asistencia.Marcacion';

    protected $primaryKey = 'MarcacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'MetodoMarcacionId',
        'DispositivoMarcacionId',
        'PlantillaBiometricaId',
        'CargaAsistenciaManualId',
        'MarcacionFechaHora',
        'MarcacionTipo',
        'MarcacionGeolocalizacion',
        'MarcacionObservacion',
        'MarcacionOrigen',
        'MarcacionEsValida',
    ];

    protected $casts = [
        'MarcacionId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'MetodoMarcacionId' => 'integer',
        'DispositivoMarcacionId' => 'integer',
        'PlantillaBiometricaId' => 'integer',
        'CargaAsistenciaManualId' => 'integer',
        'MarcacionFechaHora' => 'datetime',
        'MarcacionEsValida' => 'boolean',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function metodo(): BelongsTo
    {
        return $this->belongsTo(MetodoMarcacion::class, 'MetodoMarcacionId', 'MetodoMarcacionId');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoMarcacion::class, 'DispositivoMarcacionId', 'DispositivoMarcacionId');
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaBiometrica::class, 'PlantillaBiometricaId', 'PlantillaBiometricaId');
    }

    public function carga(): BelongsTo
    {
        return $this->belongsTo(CargaAsistenciaManual::class, 'CargaAsistenciaManualId', 'CargaAsistenciaManualId');
    }
}
