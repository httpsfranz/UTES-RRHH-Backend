<?php

namespace App\Models\Configuracion;

use Illuminate\Database\Eloquent\Model;

class ParametroSistema extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Configuracion].[ParametroSistema]
    protected $table = 'Configuracion.ParametroSistema';

    protected $primaryKey = 'ParametroSistemaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'ParametroSistemaCodigo',
        'ParametroSistemaValor',
        'ParametroSistemaDescripcion',
        'ParametroSistemaEstado',
    ];

    protected $casts = [
        'ParametroSistemaEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('ParametroSistemaEstado', 1);
    }
}
