<?php

namespace App\Models\Solicitudes;

use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Constatación domiciliaria (Solicitudes.ConstatacionDomiciliaria). Generado a partir del DDL por gen_backend3.py; las reglas viven en el Form Request y el Service. */
class ConstatacionDomiciliaria extends Model
{
    protected $table = 'Solicitudes.ConstatacionDomiciliaria';

    protected $primaryKey = 'ConstatacionDomiciliariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'VinculoLaboralId',
        'DescansoMedicoId',
        'DocumentoSustentoId',
        'UsuarioRegistroId',
        'ConstatacionDomiciliariaFecha',
        'ConstatacionDomiciliariaDireccion',
        'ConstatacionDomiciliariaResultado',
        'ConstatacionDomiciliariaEstado',
    ];

    protected $casts = [
        'ConstatacionDomiciliariaId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'DescansoMedicoId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'UsuarioRegistroId' => 'integer',
        'ConstatacionDomiciliariaFecha' => 'date',
    ];

    public function vinculoLaboral(): BelongsTo
    {
        return $this->belongsTo(VinculoLaboral::class, 'VinculoLaboralId', 'VinculoLaboralId');
    }

    public function descansoMedico(): BelongsTo
    {
        return $this->belongsTo(DescansoMedico::class, 'DescansoMedicoId', 'DescansoMedicoId');
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
