<?php

namespace App\Models\Programacion;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Informe de guardia comunitaria (Programacion.InformeGuardiaComunitaria). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class InformeGuardiaComunitaria extends Model
{
    protected $table = 'Programacion.InformeGuardiaComunitaria';

    protected $primaryKey = 'InformeGuardiaComunitariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TurnoProgramadoId',
        'DocumentoSustentoId',
        'InformeGuardiaComunitariaFecha',
        'InformeGuardiaComunitariaHoraInicio',
        'InformeGuardiaComunitariaHoraFin',
        'InformeGuardiaComunitariaDescripcion',
        'InformeGuardiaComunitariaEstado',
    ];

    protected $casts = [
        'InformeGuardiaComunitariaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TurnoProgramadoId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'InformeGuardiaComunitariaFecha' => 'date',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
