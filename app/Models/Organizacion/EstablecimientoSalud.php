<?php

namespace App\Models\Organizacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablecimientoSalud extends Model
{
    protected $table = 'Organizacion.EstablecimientoSalud';

    protected $primaryKey = 'EessId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'MicroredId',
        'TipoEstablecimientoId',
        'EessCodigo',
        'EessCodigoRenipres',
        'EessNombre',
        'EessCategoria',
        'EessUbigeo',
        'EessDireccion',
        'EessTelefono',
        'EessDescripcion',
        'EessEstado',
    ];

    protected $casts = [
        'MicroredId' => 'integer',
        'TipoEstablecimientoId' => 'integer',
        'EessEstado' => 'boolean',
    ];

    public function microred(): BelongsTo
    {
        return $this->belongsTo(Microred::class, 'MicroredId', 'MicroredId');
    }

    public function tipoEstablecimiento(): BelongsTo
    {
        return $this->belongsTo(TipoEstablecimiento::class, 'TipoEstablecimientoId', 'TipoEstablecimientoId');
    }
}
