<?php

namespace App\Models\Compensaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una liquidación de descuentos (Compensaciones.DetalleLiquidacion). Las reglas viven en el Form Request y el Service. */
class DetalleLiquidacion extends Model
{
    protected $table = 'Compensaciones.DetalleLiquidacion';

    protected $primaryKey = 'DetalleLiquidacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'LiquidacionDescuentoId',
        'ConceptoDescuentoId',
        'DetalleLiquidacionCantidad',
        'DetalleLiquidacionImporte',
        'DetalleLiquidacionObservacion',
    ];

    protected $casts = [
        'DetalleLiquidacionId' => 'integer',
        'LiquidacionDescuentoId' => 'integer',
        'ConceptoDescuentoId' => 'integer',
        'DetalleLiquidacionCantidad' => 'float',
        'DetalleLiquidacionImporte' => 'float',
    ];

    protected static function booted(): void
    {
        // El importe total de la liquidacion siempre es la suma de sus lineas.
        static::saved(fn (self $linea) => LiquidacionDescuento::query()->find($linea->LiquidacionDescuentoId)?->recalcularTotal());
        static::deleted(fn (self $linea) => LiquidacionDescuento::query()->find($linea->LiquidacionDescuentoId)?->recalcularTotal());
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionDescuento::class, 'LiquidacionDescuentoId', 'LiquidacionDescuentoId');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoDescuento::class, 'ConceptoDescuentoId', 'ConceptoDescuentoId');
    }
}
