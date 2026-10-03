<?php

namespace App\Models\Asistencia;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carga de asistencia manual (Asistencia.CargaAsistenciaManual). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class CargaAsistenciaManual extends Model
{
    protected $table = 'Asistencia.CargaAsistenciaManual';

    protected $primaryKey = 'CargaAsistenciaManualId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'UsuarioId',
        'EessId',
        'DocumentoSustentoId',
        'CargaAsistenciaManualFecha',
        'CargaAsistenciaManualNombreArchivo',
        'CargaAsistenciaManualRegistros',
        'CargaAsistenciaManualObservacion',
        'CargaAsistenciaManualEstado',
    ];

    protected $casts = [
        'CargaAsistenciaManualId' => 'integer',
        'UsuarioId' => 'integer',
        'EessId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'CargaAsistenciaManualFecha' => 'datetime',
        'CargaAsistenciaManualRegistros' => 'integer',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(Marcacion::class, 'CargaAsistenciaManualId', 'CargaAsistenciaManualId');
    }
}
