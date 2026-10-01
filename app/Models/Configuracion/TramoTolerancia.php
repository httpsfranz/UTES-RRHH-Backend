<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo de la escala de tolerancia (Art. 22 del RIT). Los minutos se miden DESDE LA HORA
 * DE INGRESO del turno. No tiene columna de Estado: se elimina de verdad (nada la referencia).
 */
class TramoTolerancia extends Model
{
    protected $table = 'Configuracion.TramoTolerancia';

    protected $primaryKey = 'TramoToleranciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TablaToleranciaId',
        'TramoToleranciaTipo',
        'TramoToleranciaMinutosDesde',
        'TramoToleranciaMinutosHasta',
        'TramoToleranciaFactorDescuento',
        'TramoToleranciaMinutosDescuento',
        'TramoToleranciaEsInasistencia',
        'TramoToleranciaDescripcion',
    ];

    protected $casts = [
        'TablaToleranciaId' => 'integer',
        'TramoToleranciaMinutosDesde' => 'integer',
        'TramoToleranciaMinutosHasta' => 'integer',
        'TramoToleranciaFactorDescuento' => 'float',
        'TramoToleranciaMinutosDescuento' => 'integer',
        'TramoToleranciaEsInasistencia' => 'boolean',
    ];

    public function tablaTolerancia(): BelongsTo
    {
        return $this->belongsTo(TablaTolerancia::class, 'TablaToleranciaId', 'TablaToleranciaId');
    }
}
