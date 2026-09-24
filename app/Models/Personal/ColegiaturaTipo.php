<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColegiaturaTipo extends Model
{
    protected $table = 'Personal.ColegiaturaTipo';

    protected $primaryKey = 'ColegiaturaTipoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'ProfesionId',
        'ColegiaturaTipoCodigo',
        'ColegiaturaTipoNombre',
        'ColegiaturaTipoEntidad',
        'ColegiaturaTipoDescripcion',
        'ColegiaturaTipoEstado',
    ];

    protected $casts = [
        'ProfesionId'           => 'integer',
        'ColegiaturaTipoEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('ColegiaturaTipoEstado', 1);
    }

    // ProfesionId es nullable: no todo tipo de colegiatura exige una profesion especifica.
    public function profesion(): BelongsTo
    {
        return $this->belongsTo(Profesion::class, 'ProfesionId', 'ProfesionId');
    }
}
