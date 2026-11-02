<?php

namespace App\Models\Personal;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Personal.Fotocheck (V007). Sus reglas viven en App\Support\RegistrosRit. */
class Fotocheck extends Model
{
    protected $table = 'Personal.Fotocheck';

    protected $primaryKey = 'FotocheckId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TrabajadorId',
        'DocumentoSustentoId',
        'FotocheckNumero',
        'FotocheckFechaEntrega',
        'FotocheckFechaDenuncia',
        'FotocheckFechaDevolucion',
        'FotocheckEsDuplicado',
        'FotocheckObservacion',
        'FotocheckEstado',
    ];

    protected $casts = [
        'FotocheckFechaEntrega' => 'date',
        'FotocheckFechaDenuncia' => 'date',
        'FotocheckFechaDevolucion' => 'date',
        'FotocheckEsDuplicado' => 'boolean',
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
