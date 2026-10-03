<?php

namespace App\Models\Solicitudes;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Papeleta (Solicitudes.Papeleta). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class Papeleta extends Model
{
    protected $table = 'Solicitudes.Papeleta';

    protected $primaryKey = 'PapeletaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TipoPapeletaId',
        'MotivoPapeletaId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'UsuarioAutorizacionId',
        'PapeletaNumero',
        'PapeletaFecha',
        'PapeletaHoraSalida',
        'PapeletaHoraRetorno',
        'PapeletaEsDiaCompleto',
        'PapeletaMinutosUtilizados',
        'PapeletaMotivo',
        'PapeletaObservacion',
        'PapeletaFechaRegistro',
        'PapeletaFechaResolucion',
        'PapeletaEstado',
    ];

    protected $casts = [
        'PapeletaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TipoPapeletaId' => 'integer',
        'MotivoPapeletaId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'UsuarioAutorizacionId' => 'integer',
        'PapeletaFecha' => 'date',
        'PapeletaEsDiaCompleto' => 'boolean',
        'PapeletaMinutosUtilizados' => 'integer',
        'PapeletaFechaRegistro' => 'datetime',
        'PapeletaFechaResolucion' => 'datetime',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoPapeleta::class, 'TipoPapeletaId', 'TipoPapeletaId');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoPapeleta::class, 'MotivoPapeletaId', 'MotivoPapeletaId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }

    public function usuarioAutorizacion(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioAutorizacionId', 'UsuarioId');
    }
}
