<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Permiso;

/**
 * Reemplaza el conjunto de permisos de un rol: PermisoIds es la lista COMPLETA que debe quedar
 * (puede estar vacia para dejar al rol sin permisos).
 */
class RolPermisosSincronizarRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'PermisoIds' => ['present', 'array', 'max:500'],
            'PermisoIds.*' => ['integer', 'distinct', $this->existe(Permiso::class)],
        ];
    }

    public function attributes(): array
    {
        return ['PermisoIds' => 'permisos', 'PermisoIds.*' => 'permiso'];
    }
}
