<?php

namespace App\Models\Seguridad;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $table = 'Seguridad.Auditoria';

    protected $primaryKey = 'AuditoriaId';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    // Sin $fillable: nadie escribe auditoria via API (ver AuditoriaController, solo lectura).
    protected $casts = [
        'UsuarioId'          => 'integer',
        'AuditoriaFechaHora' => 'datetime',
    ];
}
