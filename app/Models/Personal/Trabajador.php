<?php

namespace App\Models\Personal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trabajador extends Model
{
    protected $table = 'Personal.Trabajador';

    protected $primaryKey = 'TrabajadorId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // TrabajadorNombreCompleto es una columna calculada y persistida: la base la arma, nunca se escribe.
    // TrabajadorFechaRegistro la asigna la base (DEFAULT SYSDATETIME()).
    protected $fillable = [
        'TipoDocumentoIdentidadId',
        'ProfesionId',
        'TrabajadorNumeroDocumento',
        'TrabajadorNombres',
        'TrabajadorApellidoPaterno',
        'TrabajadorApellidoMaterno',
        'TrabajadorSexo',
        'TrabajadorFechaNacimiento',
        'TrabajadorCorreo',
        'TrabajadorTelefono',
        'TrabajadorDireccion',
        'TrabajadorFotoRuta',
        'TrabajadorEstado',
    ];

    protected $casts = [
        'TipoDocumentoIdentidadId' => 'integer',
        'ProfesionId' => 'integer',
        'TrabajadorFechaNacimiento' => 'date',
        'TrabajadorFechaRegistro' => 'datetime',
        'TrabajadorEstado' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('TrabajadorEstado', 1);
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoIdentidad::class, 'TipoDocumentoIdentidadId', 'TipoDocumentoIdentidadId');
    }

    // ProfesionId es nullable: no todo el personal es profesional.
    public function profesion(): BelongsTo
    {
        return $this->belongsTo(Profesion::class, 'ProfesionId', 'ProfesionId');
    }
}
