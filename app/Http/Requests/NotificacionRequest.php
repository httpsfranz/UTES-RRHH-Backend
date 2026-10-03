<?php

namespace App\Http\Requests;

use App\Models\Seguridad\Usuario;

class NotificacionRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'UsuarioId' => [$this->obligatorio(), 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioId')],
            // Clase de aviso: JUSTIFICACION_APROBADA, PAPELETA_RECHAZADA, GENERAL...
            'NotificacionTipo' => [$this->obligatorio(), 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]*$/D'],
            'NotificacionTitulo' => $this->textoObligatorio(200),
            'NotificacionMensaje' => $this->textoObligatorio(1000),
            // Ruta interna del sistema a la que lleva el aviso (por ejemplo /solicitudes/papeletas).
            'NotificacionEnlace' => ['nullable', 'string', 'max:300', 'regex:/^\/[A-Za-z0-9\-_\/.?=&%#]*$/D'],
            'NotificacionLeida' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'NotificacionTipo.regex' => 'El tipo solo admite mayúsculas, números y guion bajo (por ejemplo PAPELETA_APROBADA).',
            'NotificacionEnlace.regex' => 'El enlace debe ser una ruta interna que empiece con "/" (por ejemplo /solicitudes/papeletas).',
        ];
    }
}
