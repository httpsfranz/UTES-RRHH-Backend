<?php

namespace App\Models\Disciplina;

use Illuminate\Database\Eloquent\Model;

class TipoFaltaDisciplinaria extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Disciplina].[TipoFaltaDisciplinaria]
    protected $table = 'Disciplina.TipoFaltaDisciplinaria';

    protected $primaryKey = 'TipoFaltaDisciplinariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'TipoFaltaDisciplinariaCodigo',
        'TipoFaltaDisciplinariaNombre',
        'TipoFaltaDisciplinariaGravedad',
        'TipoFaltaDisciplinariaBaseLegal',
        'TipoFaltaDisciplinariaDescripcion',
        'TipoFaltaDisciplinariaEstado',
    ];

    protected $casts = [
        'TipoFaltaDisciplinariaEstado' => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('TipoFaltaDisciplinariaEstado', 1);
    }
}
