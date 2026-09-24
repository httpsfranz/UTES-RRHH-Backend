<?php

namespace App\Models\Biometria;

use Illuminate\Database\Eloquent\Model;

class MetodoMarcacion extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Biometria].[MetodoMarcacion]
    protected $table = 'Biometria.MetodoMarcacion';

    protected $primaryKey = 'MetodoMarcacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'MetodoMarcacionCodigo',
        'MetodoMarcacionNombre',
        'MetodoMarcacionDescripcion',
        'MetodoMarcacionEstado',
    ];

    protected $casts = [
        'MetodoMarcacionEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('MetodoMarcacionEstado', 1);
    }
}
