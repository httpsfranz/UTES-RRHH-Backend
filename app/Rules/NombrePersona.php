<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nombres y apellidos: letras (con tildes y enie), espacios, punto, apostrofe y guion.
 * Rechaza digitos y simbolos (un nombre como "Juan2" o "<script>" no es un nombre).
 */
class NombrePersona implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match("/^\p{L}[\p{L} .'\-]*$/uD", $value)) {
            $fail('El campo :attribute solo admite letras, espacios, punto, apóstrofe y guion.');
        }
    }
}
