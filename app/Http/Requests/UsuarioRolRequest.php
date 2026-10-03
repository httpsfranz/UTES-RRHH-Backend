<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Rol;
use App\Models\Seguridad\Usuario;
use App\Models\Seguridad\UsuarioRol;
use Illuminate\Validation\Validator;

/**
 * Roles de un usuario con vigencia. Un usuario tiene cada rol una sola vez (UQ_UsuarioRol): para volver a darle un
 * rol que tuvo, se reactiva o se ajustan las fechas de la asignacion existente.
 */
class UsuarioRolRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            'RolId' => [$this->obligatorio(), 'integer', $this->existeActivo(Rol::class, 'RolEstado', 'RolId')],
            // Si no se envia, la base pone la fecha de hoy.
            'UsuarioRolFechaInicio' => ['nullable', 'date_format:Y-m-d'],
            'UsuarioRolFechaFin' => ['nullable', 'date_format:Y-m-d'],
            'UsuarioRolEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'UsuarioRolFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'UsuarioRolFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $inicio = $this->fechaEfectiva('UsuarioRolFechaInicio') ?? now()->toDateString();
            $fin = $this->fechaEfectiva('UsuarioRolFechaFin');
            if ($fin !== null && $fin < $inicio) {
                $validator->errors()->add('UsuarioRolFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $existe = UsuarioRol::query()
                ->where('UsuarioId', $this->valorEfectivo('UsuarioId'))
                ->where('RolId', $this->valorEfectivo('RolId'))
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();
            if ($existe) {
                $validator->errors()->add('RolId', 'El usuario ya tiene (o tuvo) ese rol: reactiva o edita la asignación existente.');
            }
        });
    }

    public function datos(): array
    {
        $datos = $this->validated();

        // El inicio es obligatorio en la base (con DEFAULT): vacio significa "hoy".
        if (array_key_exists('UsuarioRolFechaInicio', $datos) && $datos['UsuarioRolFechaInicio'] === null) {
            unset($datos['UsuarioRolFechaInicio']);
        }

        return $datos;
    }
}
