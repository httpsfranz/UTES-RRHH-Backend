<?php

namespace App\Http\Requests;

use App\Models\Personal\Trabajador;
use App\Models\Soporte\DocumentoSustento;

/**
 * Registro de un evento de consentimiento (aceptacion o revocacion). Solo se crea: el historial no se edita.
 */
class ConsentimientoBiometricoRequest extends CatalogoRequest
{
    public function rules(): array
    {
        return [
            'TrabajadorId' => ['required', 'integer', $this->existeActivo(Trabajador::class, 'TrabajadorEstado', 'TrabajadorId')],
            // Formato firmado de consentimiento (Soporte.DocumentoSustento).
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Debe indicarse de forma EXPLICITA: true = acepta, false = revoca.
            'ConsentimientoBiometricoAceptado' => ['required', 'boolean'],
            'ConsentimientoBiometricoVersion' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'],
        ];
    }

    public function messages(): array
    {
        return [
            'ConsentimientoBiometricoAceptado.required' => 'Indica si el trabajador acepta o revoca el consentimiento.',
            'ConsentimientoBiometricoVersion.regex' => 'La versión solo admite letras, números, punto y guion (por ejemplo v1.0).',
        ];
    }
}
