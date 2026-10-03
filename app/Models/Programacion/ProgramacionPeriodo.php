<?php

namespace App\Models\Programacion;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Programación por período (Programacion.ProgramacionPeriodo). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class ProgramacionPeriodo extends Model
{
    protected $table = 'Programacion.ProgramacionPeriodo';

    protected $primaryKey = 'ProgramacionPeriodoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'TipoPeriodoProgramacionId',
        'UsuarioRegistroId',
        'ProgramacionPeriodoCodigo',
        'ProgramacionPeriodoAnio',
        'ProgramacionPeriodoMes',
        'ProgramacionPeriodoNumero',
        'ProgramacionPeriodoFechaInicio',
        'ProgramacionPeriodoFechaFin',
        'ProgramacionPeriodoObservacion',
        'ProgramacionPeriodoFechaRegistro',
        'ProgramacionPeriodoFechaPublicacion',
        'ProgramacionPeriodoEstado',
    ];

    protected $casts = [
        'ProgramacionPeriodoId' => 'integer',
        'EessId' => 'integer',
        'TipoPeriodoProgramacionId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'ProgramacionPeriodoAnio' => 'integer',
        'ProgramacionPeriodoMes' => 'integer',
        'ProgramacionPeriodoNumero' => 'integer',
        'ProgramacionPeriodoFechaInicio' => 'date',
        'ProgramacionPeriodoFechaFin' => 'date',
        'ProgramacionPeriodoFechaRegistro' => 'datetime',
        'ProgramacionPeriodoFechaPublicacion' => 'datetime',
    ];

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function tipoPeriodo(): BelongsTo
    {
        return $this->belongsTo(TipoPeriodoProgramacion::class, 'TipoPeriodoProgramacionId', 'TipoPeriodoProgramacionId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }
}
