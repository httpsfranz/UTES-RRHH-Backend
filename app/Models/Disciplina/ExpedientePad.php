<?php

namespace App\Models\Disciplina;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Expediente PAD (Disciplina.ExpedientePad). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class ExpedientePad extends Model
{
    protected $table = 'Disciplina.ExpedientePad';

    protected $primaryKey = 'ExpedientePadId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TipoFaltaDisciplinariaId',
        'DocumentoSustentoId',
        'ExpedientePadNumero',
        'ExpedientePadFechaInicio',
        'ExpedientePadFechaFin',
        'ExpedientePadDescripcion',
        'ExpedientePadSancion',
        'ExpedientePadEstado',
    ];

    protected $casts = [
        'ExpedientePadId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TipoFaltaDisciplinariaId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'ExpedientePadFechaInicio' => 'date',
        'ExpedientePadFechaFin' => 'date',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function tipoFalta(): BelongsTo
    {
        return $this->belongsTo(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaId', 'TipoFaltaDisciplinariaId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
