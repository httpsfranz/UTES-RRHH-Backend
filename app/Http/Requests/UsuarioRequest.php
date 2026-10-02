<?php

namespace App\Http\Requests;

use App\Models\Personal\Trabajador;
use App\Models\Seguridad\Usuario;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsuarioRequest extends CatalogoRequest
{
    /** Usuario y correo son insensibles a mayusculas: se guardan en minusculas. */
    protected function prepareForValidation(): void
    {
        foreach (['UsuarioNombre', 'UsuarioCorreo'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => mb_strtolower(trim($this->input($campo)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // Cada trabajador tiene como maximo UNA cuenta (UQ_UsuarioTrabajador).
            'TrabajadorId' => [
                $this->obligatorio(), 'integer',
                $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId'),
                $this->unico(Usuario::class, 'TrabajadorId'),
            ],
            'UsuarioNombre' => [
                $this->obligatorio(), 'string', 'between:4,100', 'regex:/^[a-z0-9][a-z0-9._-]*$/D',
                $this->unico(Usuario::class, 'UsuarioNombre'),
            ],
            // Se recibe en texto plano y se guarda solo su hash (bcrypt, 72 bytes maximo). Al editar es opcional.
            'UsuarioPassword' => [
                $this->esCreacion() ? 'required' : 'nullable', 'string', 'max:72',
                Password::min(8)->letters()->numbers(),
            ],
            'UsuarioPasswordConfirmacion' => [Rule::requiredIf(fn () => filled($this->input('UsuarioPassword'))), 'nullable', 'same:UsuarioPassword'],
            // Correo institucional de la cuenta: sirve para login/notificaciones, asi que no se repite entre cuentas.
            'UsuarioCorreo' => ['nullable', 'string', 'email', 'max:200', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/D', $this->unico(Usuario::class, 'UsuarioCorreo')],
            'UsuarioEstado' => $this->booleano(),
        ];
    }

    public function messages(): array
    {
        return [
            'UsuarioNombre.regex' => 'El usuario solo admite letras minúsculas, números, punto, guion y guion bajo, sin espacios.',
            'UsuarioNombre.between' => 'El usuario debe tener entre 4 y 100 caracteres.',
            'UsuarioNombre.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
            'TrabajadorId.unique' => 'Ese trabajador ya tiene una cuenta de usuario.',
            'UsuarioCorreo.unique' => 'Ya existe una cuenta con ese correo.',
            'UsuarioCorreo.regex' => 'Ingresa un correo electrónico válido (con dominio).',
            'UsuarioPasswordConfirmacion.same' => 'La confirmación no coincide con la contraseña.',
            'UsuarioPasswordConfirmacion.required' => 'Confirma la contraseña.',
        ];
    }

    public function attributes(): array
    {
        return [
            'UsuarioNombre' => 'usuario',
            'UsuarioPassword' => 'contraseña',
            'UsuarioPasswordConfirmacion' => 'confirmación de la contraseña',
            'UsuarioCorreo' => 'correo electrónico',
        ] + parent::attributes();
    }
}
