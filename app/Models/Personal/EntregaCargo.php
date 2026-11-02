<?php

namespace App\Models\Personal;

use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Personal.EntregaCargo (V007). Sus reglas viven en App\Support\RegistrosRit. */
class EntregaCargo extends Model
{
    protected $table = 'Personal.EntregaCargo';

    protected $primaryKey = 'EntregaCargoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'VinculoLaboralRecibeId',
        'DocumentoSustentoId',
        'EntregaCargoMotivo',
        'EntregaCargoFecha',
        'EntregaCargoDetalle',
        'EntregaCargoEstado',
    ];

    protected $casts = [
        'EntregaCargoFecha' => 'date',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function recibe(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralRecibeId', 'VinculoLaboralId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
