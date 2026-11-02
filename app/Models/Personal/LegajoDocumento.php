<?php

namespace App\Models\Personal;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Personal.LegajoDocumento (V007). Sus reglas viven en App\Support\RegistrosRit. */
class LegajoDocumento extends Model
{
    protected $table = 'Personal.LegajoDocumento';

    protected $primaryKey = 'LegajoDocumentoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TrabajadorId',
        'DocumentoSustentoId',
        'LegajoDocumentoTipo',
        'LegajoDocumentoNombre',
        'LegajoDocumentoFecha',
        'LegajoDocumentoObservacion',
        'LegajoDocumentoEstado',
    ];

    protected $casts = [
        'LegajoDocumentoFecha' => 'date',
        'LegajoDocumentoEstado' => 'boolean',
    ];

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
