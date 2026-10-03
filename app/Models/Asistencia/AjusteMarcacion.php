<?php

namespace App\Models\Asistencia;

use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ajuste de marcación (Asistencia.AjusteMarcacion). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class AjusteMarcacion extends Model
{
    protected $table = 'Asistencia.AjusteMarcacion';

    protected $primaryKey = 'AjusteMarcacionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'MarcacionId',
        'UsuarioId',
        'DocumentoSustentoId',
        'AjusteMarcacionFechaHora',
        'AjusteMarcacionFechaHoraAnterior',
        'AjusteMarcacionFechaHoraNueva',
        'AjusteMarcacionMotivo',
        'AjusteMarcacionEstado',
    ];

    protected $casts = [
        'AjusteMarcacionId' => 'integer',
        'MarcacionId' => 'integer',
        'UsuarioId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'AjusteMarcacionFechaHora' => 'datetime',
        'AjusteMarcacionFechaHoraAnterior' => 'datetime',
        'AjusteMarcacionFechaHoraNueva' => 'datetime',
    ];

    public function marcacion(): BelongsTo
    {
        return $this->belongsTo(Marcacion::class, 'MarcacionId', 'MarcacionId');
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
