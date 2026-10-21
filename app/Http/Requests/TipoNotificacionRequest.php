<?php

namespace App\Http\Requests;

use App\Models\Soporte\TipoNotificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Regla de envio de un tipo de notificacion: roles de destino (codigos de Seguridad.Rol), ambito y correo. */
class TipoNotificacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TipoNotificacionCodigo' => $this->codigoUnico(TipoNotificacion::class, 'TipoNotificacionCodigo', 50),
            'TipoNotificacionNombre' => $this->nombreUnico(TipoNotificacion::class, 'TipoNotificacionNombre', 150),
            'TipoNotificacionDescripcion' => $this->texto(300),
            'TipoNotificacionRolesDestino' => ['nullable', 'string', 'max:300', 'regex:/^[A-Z0-9_]+(,[A-Z0-9_]+)*$/D'],
            'TipoNotificacionAlcance' => [$this->obligatorio(), Rule::in(['INTERESADO', 'EESS', 'MICRORED', 'RED'])],
            'TipoNotificacionAlInteresado' => $this->booleano(),
            'TipoNotificacionEnviaCorreo' => $this->booleano(),
            'TipoNotificacionEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return ['TipoNotificacionRolesDestino.regex' => 'Indica los códigos de rol separados por coma, sin espacios (por ejemplo RRHH_RED,RESP_EESS).'];
    }

    /** Los roles de destino deben existir. */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $roles = array_filter(explode(',', (string) $this->input('TipoNotificacionRolesDestino')));
            $faltan = array_diff($roles, DB::table('Seguridad.Rol')->whereIn('RolCodigo', $roles)->pluck('RolCodigo')->all());
            if ($faltan !== []) {
                $validator->errors()->add('TipoNotificacionRolesDestino', 'No existen los roles: '.implode(', ', $faltan).'.');
            }
        });
    }
}
