<?php

namespace App\Models\Compensaciones;

use App\Models\Consolidacion\ConsolidadoAsistencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Liquidación de descuentos por inasistencias y tardanzas de un consolidado (Compensaciones.LiquidacionDescuento). Las reglas viven en el Form Request y el Service. */
class LiquidacionDescuento extends Model
{
    protected $table = 'Compensaciones.LiquidacionDescuento';

    protected $primaryKey = 'LiquidacionDescuentoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ConsolidadoAsistenciaId',
        'LiquidacionDescuentoFechaGeneracion',
        'LiquidacionDescuentoImporteTotal',
        'LiquidacionDescuentoEstado',
    ];

    protected $casts = [
        'LiquidacionDescuentoId' => 'integer',
        'ConsolidadoAsistenciaId' => 'integer',
        'LiquidacionDescuentoFechaGeneracion' => 'datetime',
        'LiquidacionDescuentoImporteTotal' => 'float',
    ];

    /** El importe total es la suma de las lineas; no se escribe a mano. */
    public function recalcularTotal(): void
    {
        static::query()->whereKey($this->getKey())->update([
            'LiquidacionDescuentoImporteTotal' => round((float) $this->detalles()->sum('DetalleLiquidacionImporte'), 2),
        ]);
    }

    public function consolidado(): BelongsTo
    {
        return $this->belongsTo(ConsolidadoAsistencia::class, 'ConsolidadoAsistenciaId', 'ConsolidadoAsistenciaId');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleLiquidacion::class, 'LiquidacionDescuentoId', 'LiquidacionDescuentoId');
    }
}
