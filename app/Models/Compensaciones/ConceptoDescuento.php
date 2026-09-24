<?php

namespace App\Models\Compensaciones;

use Illuminate\Database\Eloquent\Model;

class ConceptoDescuento extends Model
{
    protected $table = 'Compensaciones.ConceptoDescuento';
    protected $primaryKey = 'ConceptoDescuentoId';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'ConceptoDescuentoCodigo',
        'ConceptoDescuentoNombre',
        'ConceptoDescuentoDescripcion',
        'ConceptoDescuentoEstado',
    ];

    protected $casts = [
        'ConceptoDescuentoEstado'=> 'boolean',
    ];

    public function scopeActivos ($query) {
        return $query->where('ConceptoDescuentoEstado', 1);
    }
    
}
