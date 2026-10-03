<?php

namespace App\Models\Solicitudes;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Licencia (Solicitudes.Licencia). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class Licencia extends Model
{
    protected $table = 'Solicitudes.Licencia';

    protected $primaryKey = 'LicenciaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'TipoLicenciaId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'LicenciaNumeroResolucion',
        'LicenciaFechaInicio',
        'LicenciaFechaFin',
        'LicenciaMotivo',
        'LicenciaFechaRegistro',
        'LicenciaEstado',
    ];

    protected $casts = [
        'LicenciaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'TipoLicenciaId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'LicenciaFechaInicio' => 'date',
        'LicenciaFechaFin' => 'date',
        'LicenciaFechaRegistro' => 'datetime',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoLicencia::class, 'TipoLicenciaId', 'TipoLicenciaId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    public function usuarioRegistro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'UsuarioRegistroId', 'UsuarioId');
    }
}
