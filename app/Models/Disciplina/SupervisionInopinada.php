<?php

namespace App\Models\Disciplina;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supervisión inopinada (Disciplina.SupervisionInopinada). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class SupervisionInopinada extends Model
{
    protected $table = 'Disciplina.SupervisionInopinada';

    protected $primaryKey = 'SupervisionInopinadaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'VinculoLaboralId',
        'UsuarioId',
        'DocumentoSustentoId',
        'SupervisionInopinadaFechaHora',
        'SupervisionInopinadaResultado',
        'SupervisionInopinadaObservacion',
        'SupervisionInopinadaEstado',
    ];

    protected $casts = [
        'SupervisionInopinadaId' => 'integer',
        'EessId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'UsuarioId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'SupervisionInopinadaFechaHora' => 'datetime',
    ];

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
    }

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioId', 'UsuarioId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }
}
