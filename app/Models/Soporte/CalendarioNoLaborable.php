<?php

namespace App\Models\Soporte;

use App\Models\Organizacion\Microred;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarioNoLaborable extends Model
{
    protected $table = 'Soporte.CalendarioNoLaborable';

    protected $primaryKey = 'CalendarioNoLaborableId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // CalendarioNoLaborableEstado (V006): un dia inactivo ya no cuenta para la asistencia ni los calendarios.
    protected $fillable = [
        'MicroredId',
        'CalendarioNoLaborableFecha',
        'CalendarioNoLaborableTipo',
        'CalendarioNoLaborableDescripcion',
        'CalendarioNoLaborableCompensable',
        'CalendarioNoLaborableNormaSustento',
        'CalendarioNoLaborableEstado',
    ];

    protected $casts = [
        'MicroredId' => 'integer',
        'CalendarioNoLaborableFecha' => 'date',
        'CalendarioNoLaborableCompensable' => 'boolean',
    ];

    // MicroredId NULL = feriado de alcance nacional / toda la Red.
    public function microred(): BelongsTo
    {
        return $this->belongsTo(Microred::class, 'MicroredId', 'MicroredId');
    }
}
