<?php

namespace App\Models\Soporte;

use Illuminate\Database\Eloquent\Model;

class DocumentoSustento extends Model
{
    protected $table = 'Soporte.DocumentoSustento';

    protected $primaryKey = 'DocumentoSustentoId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // DocumentoSustentoFechaRegistro no va aqui: tiene DEFAULT (SYSDATETIME())
    // y la asigna la base, no el cliente.
    protected $fillable = [
        'DocumentoSustentoNombre',
        'DocumentoSustentoRuta',
        'DocumentoSustentoTipo',
        'DocumentoSustentoExtension',
        'DocumentoSustentoTamanoBytes',
        'DocumentoSustentoHash',
    ];

    protected $casts = [
        'DocumentoSustentoTamanoBytes' => 'integer',
    ];
}
