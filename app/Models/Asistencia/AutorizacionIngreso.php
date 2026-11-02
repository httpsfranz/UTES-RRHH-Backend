<?php

namespace App\Models\Asistencia;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistencia.AutorizacionIngreso (V007). Sus reglas viven en App\Support\RegistrosRit. */
class AutorizacionIngreso extends Model
{
    protected $table = 'Asistencia.AutorizacionIngreso';

    protected $primaryKey = 'AutorizacionIngresoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'UsuarioId',
        'AutorizacionIngresoFecha',
        'AutorizacionIngresoFechaRegistro',
        'AutorizacionIngresoMotivo',
        'AutorizacionIngresoEstado',
    ];

    protected $casts = [
        'AutorizacionIngresoFecha' => 'date',
        'AutorizacionIngresoFechaRegistro' => 'datetime',
        'AutorizacionIngresoEstado' => 'boolean',
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
