<?php

namespace App\Models\Asistencia;

use App\Models\Personal\VinculoLaboral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistencia diaria (Asistencia.AsistenciaDiaria). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class AsistenciaDiaria extends Model
{
    protected $table = 'Asistencia.AsistenciaDiaria';

    protected $primaryKey = 'AsistenciaDiariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TurnoProgramadoId',
        'EstadoAsistenciaId',
        'JustificacionFaltaId',
        'AsistenciaDiariaFecha',
        'AsistenciaDiariaHoraEntrada',
        'AsistenciaDiariaHoraSalida',
        'AsistenciaDiariaMinutosTardanza',
        'AsistenciaDiariaMinutosFalta',
        'AsistenciaDiariaMinutosExtra',
        'AsistenciaDiariaMinutosTrabajados',
        'AsistenciaDiariaObservacion',
        'AsistenciaDiariaFechaProceso',
    ];

    protected $casts = [
        'AsistenciaDiariaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TurnoProgramadoId' => 'integer',
        'EstadoAsistenciaId' => 'integer',
        'JustificacionFaltaId' => 'integer',
        'AsistenciaDiariaFecha' => 'date',
        'AsistenciaDiariaHoraEntrada' => 'datetime',
        'AsistenciaDiariaHoraSalida' => 'datetime',
        'AsistenciaDiariaMinutosTardanza' => 'integer',
        'AsistenciaDiariaMinutosFalta' => 'integer',
        'AsistenciaDiariaMinutosExtra' => 'integer',
        'AsistenciaDiariaMinutosTrabajados' => 'integer',
        'AsistenciaDiariaFechaProceso' => 'datetime',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoAsistencia::class, 'EstadoAsistenciaId', 'EstadoAsistenciaId');
    }

    public function justificacion(): BelongsTo
    {
        return $this->belongsTo(JustificacionFalta::class, 'JustificacionFaltaId', 'JustificacionFaltaId');
    }
}
