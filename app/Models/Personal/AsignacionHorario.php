<?php

namespace App\Models\Personal;

use App\Models\Configuracion\Horario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asignación de horario (Personal.AsignacionHorario). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class AsignacionHorario extends Model
{
    protected $table = 'Personal.AsignacionHorario';

    protected $primaryKey = 'AsignacionHorarioId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'HorarioId',
        'AsignacionHorarioFechaInicio',
        'AsignacionHorarioFechaFin',
        'AsignacionHorarioObservacion',
        'AsignacionHorarioFechaRegistro',
        'AsignacionHorarioEstado',
    ];

    protected $casts = [
        'AsignacionHorarioId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'HorarioId' => 'integer',
        'AsignacionHorarioFechaInicio' => 'date',
        'AsignacionHorarioFechaFin' => 'date',
        'AsignacionHorarioFechaRegistro' => 'datetime',
        'AsignacionHorarioEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('AsignacionHorarioEstado', 1);
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'HorarioId', 'HorarioId');
    }
}
