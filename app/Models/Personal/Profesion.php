<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profesion extends Model
{
    protected $table = 'Personal.Profesion';

    protected $primaryKey = 'ProfesionId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ProfesionCodigo',
        'ProfesionNombre',
        'ProfesionDescripcion',
        'ProfesionRequiereColegiatura',
        'ProfesionEstado',
    ];

    protected $casts = [
        'ProfesionRequiereColegiatura' => 'boolean',
        'ProfesionEstado'              => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('ProfesionEstado', 1);
    }

    public function tiposColegiatura(): HasMany
    {
        return $this->hasMany(ColegiaturaTipo::class, 'ProfesionId', 'ProfesionId');
    }
}
