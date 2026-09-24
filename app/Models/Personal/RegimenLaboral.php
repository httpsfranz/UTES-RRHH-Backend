<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class RegimenLaboral extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Personal].[RegimenLaboral]
    protected $table = 'Personal.RegimenLaboral';

    protected $primaryKey = 'RegimenLaboralId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'RegimenLaboralCodigo',
        'RegimenLaboralNombre',
        'RegimenLaboralBaseLegal',
        'RegimenLaboralDescripcion',
        'RegimenLaboralEstado',
    ];

    protected $casts = [
        'RegimenLaboralEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('RegimenLaboralEstado', 1);
    }
}
