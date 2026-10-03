<?php

namespace App\Models\Programacion;

use App\Models\Configuracion\Turno;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cambio de turno (Programacion.CambioTurno). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class CambioTurno extends Model
{
    protected $table = 'Programacion.CambioTurno';

    protected $primaryKey = 'CambioTurnoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'TipoCambioTurnoId',
        'TurnoProgramadoId',
        'TurnoProgramadoContraparteId',
        'TurnoIdNuevo',
        'VinculoLaboralSolicitanteId',
        'VinculoLaboralReemplazanteId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'UsuarioAprobacionId',
        'CambioTurnoFechaSolicitud',
        'CambioTurnoFechaResolucion',
        'CambioTurnoMotivo',
        'CambioTurnoObservacion',
        'CambioTurnoEstado',
    ];

    protected $casts = [
        'CambioTurnoId' => 'integer',
        'TipoCambioTurnoId' => 'integer',
        'TurnoProgramadoId' => 'integer',
        'TurnoProgramadoContraparteId' => 'integer',
        'TurnoIdNuevo' => 'integer',
        'VinculoLaboralSolicitanteId' => 'integer',
        'VinculoLaboralReemplazanteId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'UsuarioAprobacionId' => 'integer',
        'CambioTurnoFechaSolicitud' => 'datetime',
        'CambioTurnoFechaResolucion' => 'datetime',
    ];

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoCambioTurno::class, 'TipoCambioTurnoId', 'TipoCambioTurnoId');
    }

    public function turnoProgramado(): BelongsTo
    {
        return $this->belongsTo(TurnoProgramado::class, 'TurnoProgramadoId', 'TurnoProgramadoId');
    }

    public function contraparte(): BelongsTo
    {
        return $this->belongsTo(TurnoProgramado::class, 'TurnoProgramadoContraparteId', 'TurnoProgramadoId');
    }

    public function turnoNuevo(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'TurnoIdNuevo', 'TurnoId');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralSolicitanteId', 'VinculoLaboralId');
    }

    public function reemplazante(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralReemplazanteId', 'VinculoLaboralId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }

    public function usuarioAprobacion(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioAprobacionId', 'UsuarioId');
    }
}
