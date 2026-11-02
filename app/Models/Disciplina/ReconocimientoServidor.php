<?php

namespace App\Models\Disciplina;

use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Disciplina.ReconocimientoServidor (V007). Sus reglas viven en App\Support\RegistrosRit. */
class ReconocimientoServidor extends Model
{
    protected $table = 'Disciplina.ReconocimientoServidor';

    protected $primaryKey = 'ReconocimientoServidorId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'DocumentoSustentoId',
        'ReconocimientoServidorAnio',
        'ReconocimientoServidorTipo',
        'ReconocimientoServidorMotivo',
        'ReconocimientoServidorResolucion',
        'ReconocimientoServidorFecha',
        'ReconocimientoServidorEstado',
    ];

    protected $casts = [
        'ReconocimientoServidorAnio' => 'integer',
        'ReconocimientoServidorFecha' => 'date',
        'ReconocimientoServidorEstado' => 'boolean',
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
