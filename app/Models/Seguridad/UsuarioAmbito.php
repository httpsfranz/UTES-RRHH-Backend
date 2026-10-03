<?php

namespace App\Models\Seguridad;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Organizacion\Microred;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ámbito de usuario (Seguridad.UsuarioAmbito). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class UsuarioAmbito extends Model
{
    protected $table = 'Seguridad.UsuarioAmbito';

    protected $primaryKey = 'UsuarioAmbitoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'UsuarioId',
        'MicroredId',
        'EessId',
        'UsuarioAmbitoEstado',
    ];

    protected $casts = [
        'UsuarioAmbitoId' => 'integer',
        'UsuarioId' => 'integer',
        'MicroredId' => 'integer',
        'EessId' => 'integer',
        'UsuarioAmbitoEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('UsuarioAmbitoEstado', 1);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }

    public function microred(): BelongsTo
    {
        return $this->belongsTo(Microred::class, 'MicroredId', 'MicroredId');
    }

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }
}
