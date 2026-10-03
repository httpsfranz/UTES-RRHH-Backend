<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cuerpo de las acciones aprobar / rechazar de las solicitudes (justificaciones, papeletas, licencias,
 * descansos medicos, informes...): quien resuelve y, al rechazar, el motivo.
 */
class ResolucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mientras no exista el modulo de Seguridad (M04), true.
        return true;
    }

    public function rules(): array
    {
        return [
            // Quien resuelve. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioId' => ['required', 'integer', Rule::exists(Usuario::class, 'UsuarioId')->where('UsuarioEstado', 1)],
            'Motivo' => [$this->esRechazo() ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['UsuarioId' => 'usuario que resuelve', 'Motivo' => 'motivo'];
    }

    public function messages(): array
    {
        return [
            'Motivo.required' => 'Indica el motivo del rechazo.',
            'UsuarioId.exists' => 'El usuario que resuelve no existe o está inactivo.',
        ];
    }

    private function esRechazo(): bool
    {
        return str_ends_with($this->path(), '/rechazar');
    }
}
