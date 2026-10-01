<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Permiso;
use App\Models\Seguridad\Rol;
use App\Models\Seguridad\RolPermiso;
use Illuminate\Validation\Validator;

class RolPermisoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'RolId' => [$this->obligatorio(), 'integer', $this->existeActivo(Rol::class, 'RolEstado', 'RolId')],
            'PermisoId' => [$this->obligatorio(), 'integer', $this->existeActivo(Permiso::class, 'PermisoEstado', 'PermisoId')],
            'RolPermisoEstado' => $this->booleano(),
        ];
    }

    /** UNIQUE (RolId, PermisoId): un permiso se asigna una sola vez a cada rol. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $existe = RolPermiso::query()
                ->where('RolId', $this->valorEfectivo('RolId'))
                ->where('PermisoId', $this->valorEfectivo('PermisoId'))
                ->when($this->registroId(), fn ($q, $id) => $q->where('RolPermisoId', '!=', $id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('PermisoId', 'Ese permiso ya está asignado al rol.');
            }
        });
    }
}
