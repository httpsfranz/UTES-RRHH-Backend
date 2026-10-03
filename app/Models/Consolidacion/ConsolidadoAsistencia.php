<?php

namespace App\Models\Consolidacion;

use App\Models\Compensaciones\LiquidacionDescuento;
use App\Models\Personal\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Consolidado de asistencia (Consolidacion.ConsolidadoAsistencia). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class ConsolidadoAsistencia extends Model
{
    protected $table = 'Consolidacion.ConsolidadoAsistencia';

    protected $primaryKey = 'ConsolidadoAsistenciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'PeriodoAsistenciaId',
        'VinculoLaboralId',
        'ConsolidadoAsistenciaDiasTrabajados',
        'ConsolidadoAsistenciaDiasFalta',
        'ConsolidadoAsistenciaDiasFaltaJustificada',
        'ConsolidadoAsistenciaMinutosTardanza',
        'ConsolidadoAsistenciaMinutosExtra',
        'ConsolidadoAsistenciaFechaGeneracion',
        'ConsolidadoAsistenciaEstado',
    ];

    protected $casts = [
        'ConsolidadoAsistenciaId' => 'integer',
        'PeriodoAsistenciaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'ConsolidadoAsistenciaDiasTrabajados' => 'float',
        'ConsolidadoAsistenciaDiasFalta' => 'float',
        'ConsolidadoAsistenciaDiasFaltaJustificada' => 'float',
        'ConsolidadoAsistenciaMinutosTardanza' => 'integer',
        'ConsolidadoAsistenciaMinutosExtra' => 'integer',
        'ConsolidadoAsistenciaFechaGeneracion' => 'datetime',
    ];

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoAsistencia::class, 'PeriodoAsistenciaId', 'PeriodoAsistenciaId');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function liquidacion(): HasOne
    {
        return $this->hasOne(LiquidacionDescuento::class, 'ConsolidadoAsistenciaId', 'ConsolidadoAsistenciaId');
    }
}
