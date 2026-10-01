<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cargo extends Model
{
    protected $table = 'Personal.Cargo';

    protected $primaryKey = 'CargoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // El cargo es el puesto; la colegiatura es del trabajador (Personal.Colegiatura), no del cargo.
    protected $fillable = [
        'GrupoOcupacionalId',
        'CargoCodigo',
        'CargoNombre',
        'CargoDescripcion',
        'CargoEsJefatura',
        'CargoEstado',
    ];

    protected $casts = [
        'GrupoOcupacionalId' => 'integer',
        'CargoEsJefatura' => 'boolean',
        'CargoEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('CargoEstado', 1);
    }

    public function grupoOcupacional(): BelongsTo
    {
        return $this->belongsTo(GrupoOcupacional::class, 'GrupoOcupacionalId', 'GrupoOcupacionalId');
    }
}
