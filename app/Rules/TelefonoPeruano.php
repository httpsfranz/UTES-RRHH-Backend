<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Telefono peruano: exactamente 9 digitos, sin espacios, guiones ni prefijo (+51).
 */
class TelefonoPeruano implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('El campo :attribute debe tener exactamente 9 dígitos numéricos.');

            return;
        }

        if (! preg_match('/^\d{9}$/D', (string) $value)) {
            $fail('El campo :attribute debe tener exactamente 9 dígitos numéricos (sin espacios, guiones ni +51).');
        }
    }
}
