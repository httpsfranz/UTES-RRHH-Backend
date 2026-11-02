<?php

namespace App\Models\Asistencia;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Seguridad\Usuario;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistencia.ToleranciaExtraordinaria (V007). Sus reglas viven en App\Support\RegistrosRit. */
class ToleranciaExtraordinaria extends Model
{
    protected $table = 'Asistencia.ToleranciaExtraordinaria';

    protected $primaryKey = 'ToleranciaExtraordinariaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'UsuarioId',
        'DocumentoSustentoId',
        'ToleranciaExtraordinariaFechaInicio',
        'ToleranciaExtraordinariaFechaFin',
        'ToleranciaExtraordinariaMinutos',
        'ToleranciaExtraordinariaMotivo',
        'ToleranciaExtraordinariaEstado',
    ];

    protected $casts = [
        'ToleranciaExtraordinariaFechaInicio' => 'date',
        'ToleranciaExtraordinariaFechaFin' => 'date',
        'ToleranciaExtraordinariaMinutos' => 'integer',
        'ToleranciaExtraordinariaEstado' => 'boolean',
    ];

    public function eess(): BelongsTo
    {
        return $this->belongsTo(EstablecimientoSalud::class, 'EessId', 'EessId');
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
