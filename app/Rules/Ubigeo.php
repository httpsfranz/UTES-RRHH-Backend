<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ubigeo INEI: 6 digitos (departamento, provincia, distrito).
 */
class Ubigeo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{6}$/D', (string) $value)) {
            $fail('El campo :attribute debe tener exactamente 6 dígitos numéricos (código INEI).');
        }
    }
}
