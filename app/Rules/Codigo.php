<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Codigo de catalogo (MR-LE, DL1057, ADM-D, asistencia.ver): letras, numeros y
 * los separadores - _ . : sin espacios, empezando por letra o numero.
 */
class Codigo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]*$/D', $value)) {
            $fail('El campo :attribute solo admite letras, números, guion (-), guion bajo (_), punto y dos puntos, sin espacios.');
        }
    }
}
