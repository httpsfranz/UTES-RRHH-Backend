<?php

namespace App\Models\Solicitudes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotivoPapeleta extends Model
{
    protected $table = 'Solicitudes.MotivoPapeleta';

    protected $primaryKey = 'MotivoPapeletaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TipoPapeletaId',
        'MotivoPapeletaCodigo',
        'MotivoPapeletaNombre',
        'MotivoPapeletaDescripcion',
        'MotivoPapeletaEstado',
    ];

    protected $casts = [
        'TipoPapeletaId' => 'integer',
        'MotivoPapeletaEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('MotivoPapeletaEstado', 1);
    }

    public function tipoPapeleta(): BelongsTo
    {
        return $this->belongsTo(TipoPapeleta::class, 'TipoPapeletaId', 'TipoPapeletaId');
    }
}
