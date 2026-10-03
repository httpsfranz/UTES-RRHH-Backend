<?php

namespace App\Models\Organizacion;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Responsable de EESS (Organizacion.ResponsableEess). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class ResponsableEess extends Model
{
    protected $table = 'Organizacion.ResponsableEess';

    protected $primaryKey = 'ResponsableEessId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'VinculoLaboralId',
        'TipoResponsabilidadId',
        'DocumentoSustentoId',
        'ResponsableEessFechaInicio',
        'ResponsableEessFechaFin',
        'ResponsableEessDocumentoNumero',
        'ResponsableEessObservacion',
        'ResponsableEessFechaRegistro',
        'ResponsableEessEstado',
    ];

    protected $casts = [
        'ResponsableEessId' => 'integer',
        'EessId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TipoResponsabilidadId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'ResponsableEessFechaInicio' => 'date',
        'ResponsableEessFechaFin' => 'date',
        'ResponsableEessFechaRegistro' => 'datetime',
        'ResponsableEessEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('ResponsableEessEstado', 1);
    }

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function tipoResponsabilidad(): BelongsTo
    {
        return $this->belongsTo(TipoResponsabilidad::class, 'TipoResponsabilidadId', 'TipoResponsabilidadId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
