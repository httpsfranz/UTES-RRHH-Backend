<?php

namespace App\Models\Consolidacion;

use App\Models\Asistencia\AsistenciaDiaria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Detalle del consolidado (Consolidacion.DetalleConsolidado). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class DetalleConsolidado extends Model
{
    protected $table = 'Consolidacion.DetalleConsolidado';

    protected $primaryKey = 'DetalleConsolidadoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ConsolidadoAsistenciaId',
        'AsistenciaDiariaId',
        'DetalleConsolidadoFecha',
        'DetalleConsolidadoEstado',
        'DetalleConsolidadoMinutosTardanza',
        'DetalleConsolidadoMinutosExtra',
        'DetalleConsolidadoEsJustificada',
    ];

    protected $casts = [
        'DetalleConsolidadoId' => 'integer',
        'ConsolidadoAsistenciaId' => 'integer',
        'AsistenciaDiariaId' => 'integer',
        'DetalleConsolidadoFecha' => 'date',
        'DetalleConsolidadoMinutosTardanza' => 'integer',
        'DetalleConsolidadoMinutosExtra' => 'integer',
        'DetalleConsolidadoEsJustificada' => 'boolean',
    ];

    public function consolidado(): BelongsTo
    {
        return $this->belongsTo(ConsolidadoAsistencia::class, 'ConsolidadoAsistenciaId', 'ConsolidadoAsistenciaId');
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDiaria::class, 'AsistenciaDiariaId', 'AsistenciaDiariaId');
    }
}
