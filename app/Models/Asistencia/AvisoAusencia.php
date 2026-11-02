<?php

namespace App\Models\Asistencia;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistencia.AvisoAusencia (V007). Sus reglas viven en App\Support\RegistrosRit. */
class AvisoAusencia extends Model
{
    protected $table = 'Asistencia.AvisoAusencia';

    protected $primaryKey = 'AvisoAusenciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'UsuarioId',
        'AvisoAusenciaFecha',
        'AvisoAusenciaFechaHora',
        'AvisoAusenciaMedio',
        'AvisoAusenciaMotivo',
        'AvisoAusenciaDentroDePlazo',
        'AvisoAusenciaEstado',
    ];

    protected $casts = [
        'AvisoAusenciaFecha' => 'date',
        'AvisoAusenciaFechaHora' => 'datetime',
        'AvisoAusenciaDentroDePlazo' => 'boolean',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }
}
