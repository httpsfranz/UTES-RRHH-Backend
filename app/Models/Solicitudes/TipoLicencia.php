<?php

namespace App\Models\Solicitudes;

use Illuminate\Database\Eloquent\Model;

class TipoLicencia extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Solicitudes].[TipoLicencia]
    protected $table = 'Solicitudes.TipoLicencia';

    protected $primaryKey = 'TipoLicenciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoLicenciaCodigo',
        'TipoLicenciaNombre',
        'TipoLicenciaDescripcion',
        'TipoLicenciaConGoce',
        'TipoLicenciaMaximoDias',
        'TipoLicenciaBaseLegal',
        'TipoLicenciaEstado',
    ];

    protected $casts = [
        'TipoLicenciaConGoce'    => 'boolean', // BIT -> true/false
        'TipoLicenciaMaximoDias' => 'integer',
        'TipoLicenciaEstado'     => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoLicenciaEstado', 1);
    }
}
