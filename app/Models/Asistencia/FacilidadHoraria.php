<?php

namespace App\Models\Asistencia;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistencia.FacilidadHoraria (V007). Sus reglas viven en App\Support\RegistrosRit. */
class FacilidadHoraria extends Model
{
    protected $table = 'Asistencia.FacilidadHoraria';

    protected $primaryKey = 'FacilidadHorariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'DocumentoSustentoId',
        'FacilidadHorariaMomento',
        'FacilidadHorariaMinutos',
        'FacilidadHorariaFechaInicio',
        'FacilidadHorariaFechaFin',
        'FacilidadHorariaObservacion',
        'FacilidadHorariaEstado',
    ];

    protected $casts = [
        'FacilidadHorariaMinutos' => 'integer',
        'FacilidadHorariaFechaInicio' => 'date',
        'FacilidadHorariaFechaFin' => 'date',
        'FacilidadHorariaEstado' => 'boolean',
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
