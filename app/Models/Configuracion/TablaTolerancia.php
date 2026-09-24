<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;

class TablaTolerancia extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Configuracion].[TablaTolerancia]
    protected $table = 'Configuracion.TablaTolerancia';

    protected $primaryKey = 'TablaToleranciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TablaToleranciaCodigo',
        'TablaToleranciaNombre',
        'TablaToleranciaDescripcion',
        'TablaToleranciaEstado',
    ];

    protected $casts = [
        'TablaToleranciaEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TablaToleranciaEstado', 1);
    }
}
