<?php

namespace App\Models\Solicitudes;

use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuaderno de ocurrencias de porteria/vigilancia (RIT Art. 21): salidas con o sin papeleta,
 * retornos, excesos de tiempo, ingresos fuera de horario. Su estado es un ciclo de vida
 * (REGISTRADO -> ATENDIDO, o ANULADO), no un booleano.
 */
class OcurrenciaPorteria extends Model
{
    public const ESTADOS = ['REGISTRADO', 'ATENDIDO', 'ANULADO'];

    protected $table = 'Solicitudes.OcurrenciaPorteria';

    protected $primaryKey = 'OcurrenciaPorteriaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'EessId',
        'VinculoLaboralId',
        'UsuarioId',
        'OcurrenciaPorteriaFechaHora',
        'OcurrenciaPorteriaTipo',
        'OcurrenciaPorteriaDescripcion',
        'OcurrenciaPorteriaEstado',
    ];

    protected $casts = [
        'EessId' => 'integer',
        'VinculoLaboralId' => 'integer',
        'UsuarioId' => 'integer',
        'OcurrenciaPorteriaFechaHora' => 'datetime',
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

    public function estaAnulada(): bool
    {
        return $this->OcurrenciaPorteriaEstado === 'ANULADO';
    }
}
