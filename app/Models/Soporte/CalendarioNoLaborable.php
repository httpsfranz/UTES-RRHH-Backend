<?php

namespace App\Models\Soporte;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Organizacion\Microred;

class CalendarioNoLaborable extends Model
{
    protected $table = 'Soporte.CalendarioNoLaborable';

    protected $primaryKey = 'CalendarioNoLaborableId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // No tiene columna de Estado: no hay baja logica posible para esta tabla.
    protected $fillable = [
        'MicroredId',
        'CalendarioNoLaborableFecha',
        'CalendarioNoLaborableTipo',
        'CalendarioNoLaborableDescripcion',
        'CalendarioNoLaborableCompensable',
        'CalendarioNoLaborableNormaSustento',
    ];

    protected $casts = [
        'MicroredId'                       => 'integer',
        'CalendarioNoLaborableFecha'       => 'date',
        'CalendarioNoLaborableCompensable' => 'boolean',
    ];

    // MicroredId NULL = feriado de alcance nacional / toda la Red.
    public function microred(): BelongsTo
    {
        return $this->belongsTo(Microred::class, 'MicroredId', 'MicroredId');
    }
}
