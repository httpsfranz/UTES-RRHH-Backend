<?php

namespace App\Models\Programacion;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Carga de programación (Programacion.CargaProgramacion). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class CargaProgramacion extends Model
{
    protected $table = 'Programacion.CargaProgramacion';

    protected $primaryKey = 'CargaProgramacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'TipoPeriodoProgramacionId',
        'ProgramacionPeriodoId',
        'CargaProgramacionCodigo',
        'CargaProgramacionAnio',
        'CargaProgramacionMes',
        'CargaProgramacionNumero',
        'CargaProgramacionFechaDocumento',
        'CargaProgramacionDocumentoNumero',
        'CargaProgramacionMotivo',
        'CargaProgramacionObservacion',
        'CargaProgramacionFechaRegistro',
        'CargaProgramacionEstado',
    ];

    protected $casts = [
        'CargaProgramacionId' => 'integer',
        'EessId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'TipoPeriodoProgramacionId' => 'integer',
        'ProgramacionPeriodoId' => 'integer',
        'CargaProgramacionAnio' => 'integer',
        'CargaProgramacionMes' => 'integer',
        'CargaProgramacionNumero' => 'integer',
        'CargaProgramacionFechaDocumento' => 'date',
        'CargaProgramacionFechaRegistro' => 'datetime',
    ];

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }

    public function tipoPeriodo(): BelongsTo
    {
        return $this->belongsTo(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionId', 'TipoPeriodoProgramacionId');
    }

    public function programacion(): BelongsTo
    {
        return $this->belongsTo(ProgramacionPeriodo::class, 'ProgramacionPeriodoId', 'ProgramacionPeriodoId');
    }
}
