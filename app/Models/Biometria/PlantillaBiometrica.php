<?php

namespace App\Models\Biometria;

use App\Models\Personal\Trabajador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla biometrica (vector de rasgos, pocos cientos de bytes) de un trabajador. Dato personal
 * sensible: la referencia binaria NUNCA se lee ni se devuelve por la API; solo se informa si existe
 * y su tamano. Se escribe unicamente desde App\Services\PlantillaBiometricaService.
 */
class PlantillaBiometrica extends Model
{
    protected $table = 'Biometria.PlantillaBiometrica';

    protected $primaryKey = 'PlantillaBiometricaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $hidden = ['PlantillaBiometricaReferencia'];

    // La referencia no es fillable: se asigna aparte, como binario.
    protected $fillable = [
        'TrabajadorId',
        'PlantillaBiometricaTipo',
        'PlantillaBiometricaDedo',
        'PlantillaBiometricaEstado',
    ];

    protected $casts = [
        'TrabajadorId' => 'integer',
        'PlantillaBiometricaFechaRegistro' => 'datetime',
        'PlantillaBiometricaEstado' => 'boolean',
    ];

    /** Todas las columnas MENOS el binario, mas su tamano en bytes. */
    public function scopeSinReferencia(Builder $query): Builder
    {
        return $query->select([
            'PlantillaBiometricaId', 'TrabajadorId', 'PlantillaBiometricaTipo', 'PlantillaBiometricaDedo',
            'PlantillaBiometricaFechaRegistro', 'PlantillaBiometricaEstado',
        ])->selectRaw('DATALENGTH(PlantillaBiometricaReferencia) AS PlantillaBiometricaReferenciaBytes');
    }

    // El route model binding tampoco debe traer el binario.
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query->sinReferencia(), $value, $field);
    }

    public function scopeActivos($query)
    {
        return $query->where('PlantillaBiometricaEstado', 1);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'TrabajadorId', 'TrabajadorId');
    }
}
