<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;

class CondicionLaboral extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Personal].[CondicionLaboral]
    protected $table = 'Personal.CondicionLaboral';

    protected $primaryKey = 'CondicionLaboralId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'CondicionLaboralCodigo',
        'CondicionLaboralNombre',
        'CondicionLaboralDescripcion',
        'CondicionLaboralEsPermanente',
        'CondicionLaboralRequiereAirhsp',
        'CondicionLaboralEstado',
    ];

    protected $casts = [
        'CondicionLaboralEsPermanente'    => 'boolean',
        'CondicionLaboralRequiereAirhsp'  => 'boolean',
        'CondicionLaboralEstado'          => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('CondicionLaboralEstado', 1);
    }
}
