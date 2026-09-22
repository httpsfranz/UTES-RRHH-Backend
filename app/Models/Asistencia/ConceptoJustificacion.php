<?php

namespace App\Models\Asistencia;

use Illuminate\Database\Eloquent\Model;

class ConceptoJustificacion extends Model
{
    // Eloquent parte por el punto y lo envuelve como [Asistencia].[ConceptoJustificacion]
    protected $table = 'Asistencia.ConceptoJustificacion';

    protected $primaryKey = 'ConceptoJustificacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    // La tabla no tiene created_at / updated_at
    public $timestamps = false;

    // La PK nunca va aca.
    protected $fillable = [
        'ConceptoJustificacionCodigo',
        'ConceptoJustificacionNombre',
        'ConceptoJustificacionDescripcion',
        'ConceptoJustificacionRequiereDocumento',
        'ConceptoJustificacionEsRemunerado',
        'ConceptoJustificacionEstado',
    ];

    protected $casts = [
        'ConceptoJustificacionRequiereDocumento' => 'boolean', // BIT -> true/false
        'ConceptoJustificacionEsRemunerado'      => 'boolean',
        'ConceptoJustificacionEstado'            => 'boolean',
    ];

    // --- Scopes: filtros reutilizables ---
    public function scopeActivos($query)
    {
        return $query->where('ConceptoJustificacionEstado', 1);
    }
}
