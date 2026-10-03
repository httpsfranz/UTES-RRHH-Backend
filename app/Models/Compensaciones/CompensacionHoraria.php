<?php

namespace App\Models\Compensaciones;

use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Compensación horaria (Compensaciones.CompensacionHoraria). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class CompensacionHoraria extends Model
{
    protected $table = 'Compensaciones.CompensacionHoraria';

    protected $primaryKey = 'CompensacionHorariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TipoCompensacionId',
        'AsistenciaDiariaId',
        'CompensacionHorariaAutorizadoPor',
        'CompensacionHorariaFechaGeneracion',
        'CompensacionHorariaHorasGeneradas',
        'CompensacionHorariaHorasDevueltas',
        'CompensacionHorariaFechaLimite',
        'CompensacionHorariaAutorizadoPreviamente',
        'CompensacionHorariaObservacion',
        'CompensacionHorariaEstado',
    ];

    protected $casts = [
        'CompensacionHorariaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TipoCompensacionId' => 'integer',
        'AsistenciaDiariaId' => 'integer',
        'CompensacionHorariaAutorizadoPor' => 'integer',
        'CompensacionHorariaFechaGeneracion' => 'datetime',
        'CompensacionHorariaHorasGeneradas' => 'float',
        'CompensacionHorariaHorasDevueltas' => 'float',
        'CompensacionHorariaFechaLimite' => 'date',
        'CompensacionHorariaAutorizadoPreviamente' => 'boolean',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoCompensacion::class, 'TipoCompensacionId', 'TipoCompensacionId');
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDiaria::class, 'AsistenciaDiariaId', 'AsistenciaDiariaId');
    }

    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'CompensacionHorariaAutorizadoPor', 'UsuarioId');
    }
}
