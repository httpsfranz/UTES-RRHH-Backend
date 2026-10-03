<?php

namespace App\Models\Solicitudes;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Descanso médico (Solicitudes.DescansoMedico). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class DescansoMedico extends Model
{
    protected $table = 'Solicitudes.DescansoMedico';

    protected $primaryKey = 'DescansoMedicoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'DocumentoSustentoId',
        'DescansoMedicoNumeroCitt',
        'DescansoMedicoDiagnostico',
        'DescansoMedicoFechaInicio',
        'DescansoMedicoFechaFin',
        'DescansoMedicoObservacion',
        'DescansoMedicoFechaRegistro',
        'DescansoMedicoEstado',
    ];

    protected $casts = [
        'DescansoMedicoId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'DescansoMedicoFechaInicio' => 'date',
        'DescansoMedicoFechaFin' => 'date',
        'DescansoMedicoFechaRegistro' => 'datetime',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
