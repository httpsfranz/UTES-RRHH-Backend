<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class GrupoOcupacional extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Personal].[GrupoOcupacional]
    protected $table = 'Personal.GrupoOcupacional';

    protected $primaryKey = 'GrupoOcupacionalId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'GrupoOcupacionalCodigo',
        'GrupoOcupacionalNombre',
        'GrupoOcupacionalDescripcion',
        'GrupoOcupacionalEstado',
    ];

    protected $casts = [
        'GrupoOcupacionalEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('GrupoOcupacionalEstado', 1);
    }
}
