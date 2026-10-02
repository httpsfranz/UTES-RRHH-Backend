<?php

namespace App\Models\Biometria;

use App\Models\Personal\Trabajador;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de consentimientos para el tratamiento de datos biometricos. Cada fila es un EVENTO
 * (el trabajador acepta o revoca): no se edita ni se borra. El estado vigente de un trabajador es su
 * consentimiento mas reciente.
 */
class ConsentimientoBiometrico extends Model
{
    protected $table = 'Biometria.ConsentimientoBiometrico';

    protected $primaryKey = 'ConsentimientoBiometricoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // ConsentimientoBiometricoFecha la asigna la base (DEFAULT SYSDATETIME()): la fecha del evento no la elige el cliente.
    protected $fillable = [
        'TrabajadorId',
        'DocumentoSustentoId',
        'ConsentimientoBiometricoAceptado',
        'ConsentimientoBiometricoVersion',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'DocumentoSustentoId' => 'integer',
        'ConsentimientoBiometricoFecha' => 'datetime',
        'ConsentimientoBiometricoAceptado' => 'boolean',
    ];

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoSustento::class, 'DocumentoSustentoId', 'DocumentoSustentoId');
    }

    /** El consentimiento vigente de un trabajador: el ultimo que registro. */
    public static function vigenteDe(int $trabajadorId): ?self
    {
        return static::query()->where('TrabajadorId', $trabajadorId)->orderByDesc('ConsentimientoBiometricoId')->first();
    }
}
