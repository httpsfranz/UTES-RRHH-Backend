<?php

namespace App\Models\Organizacion;

use Illuminate\Database\Eloquent\Model;

class Microred extends Model
{
    protected $table = 'Organizacion.Microred';   // esquema.tabla
    protected $primaryKey = 'MicroredId';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;                    // la tabla no tiene created_at/updated_at

    protected $fillable = [
        'MicroredCodigo',
        'MicroredNombre',
        'MicroredDistrito',
        'MicroredUbigeo',
        'MicroredDireccion',
        'MicroredTelefono',
        'MicroredDescripcion',
        'MicroredEstado',
    ];

    protected $casts = [
        'MicroredEstado' => 'boolean',
    ];
}