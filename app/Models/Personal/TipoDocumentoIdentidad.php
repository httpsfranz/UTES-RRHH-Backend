<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentoIdentidad extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Personal].[TipoDocumentoIdentidad]
    protected $table = 'Personal.TipoDocumentoIdentidad';

    protected $primaryKey = 'TipoDocumentoIdentidadId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca. Esta tabla no tiene columna de Descripcion.
    protected $fillable = [
        'TipoDocumentoIdentidadCodigo',
        'TipoDocumentoIdentidadNombre',
        'TipoDocumentoIdentidadAbreviatura',
        'TipoDocumentoIdentidadLongitud',
        'TipoDocumentoIdentidadEstado',
    ];

    protected $casts = [
        'TipoDocumentoIdentidadLongitud' => 'integer', // TINYINT -> int en PHP
        'TipoDocumentoIdentidadEstado'   => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoDocumentoIdentidadEstado', 1);
    }
}
