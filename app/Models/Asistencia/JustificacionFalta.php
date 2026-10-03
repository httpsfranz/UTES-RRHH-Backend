<?php

namespace App\Models\Asistencia;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Justificación de falta (Asistencia.JustificacionFalta). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class JustificacionFalta extends Model
{
    protected $table = 'Asistencia.JustificacionFalta';

    protected $primaryKey = 'JustificacionFaltaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'ConceptoJustificacionId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'UsuarioResolucionId',
        'JustificacionFaltaFechaInicio',
        'JustificacionFaltaFechaFin',
        'JustificacionFaltaDocumentoNumero',
        'JustificacionFaltaObservacion',
        'JustificacionFaltaMotivoRechazo',
        'JustificacionFaltaFechaRegistro',
        'JustificacionFaltaFechaResolucion',
        'JustificacionFaltaEstado',
    ];

    protected $casts = [
        'JustificacionFaltaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'ConceptoJustificacionId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'UsuarioResolucionId' => 'integer',
        'JustificacionFaltaFechaInicio' => 'date',
        'JustificacionFaltaFechaFin' => 'date',
        'JustificacionFaltaFechaRegistro' => 'datetime',
        'JustificacionFaltaFechaResolucion' => 'datetime',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoJustificacion::class, 'ConceptoJustificacionId', 'ConceptoJustificacionId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }

    public function usuarioResolucion(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioResolucionId', 'UsuarioId');
    }
}
