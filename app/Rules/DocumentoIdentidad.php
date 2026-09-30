<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numero de documento de identidad segun el tipo (Personal.TipoDocumentoIdentidad).
 *
 * DNI          : exactamente 8 digitos.
 * Resto        : alfanumerico; si el tipo declara TipoDocumentoIdentidadLongitud, ese largo exacto
 *                para CE/PTP (solo digitos) y hasta ese largo para pasaporte (alfanumerico).
 *
 * Aun no lo usa ningun Request de Nivel 0; queda listo para Personal.Trabajador (M03).
 */
class DocumentoIdentidad implements ValidationRule
{
    public function __construct(
        private readonly string $codigoTipo,
        private readonly ?int $longitud = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $numero = is_scalar($value) ? (string) $value : '';

        $valido = match (strtoupper($this->codigoTipo)) {
            'DNI' => preg_match('/^\d{8}$/D', $numero) === 1,
            'CE', 'PTP' => preg_match('/^\d+$/D', $numero) === 1
                && ($this->longitud === null || strlen($numero) === $this->longitud),
            default => preg_match('/^[A-Za-z0-9]+$/D', $numero) === 1
                && ($this->longitud === null || strlen($numero) <= $this->longitud),
        };

        if (! $valido) {
            $fail(match (strtoupper($this->codigoTipo)) {
                'DNI' => 'El DNI debe tener exactamente 8 dígitos numéricos.',
                default => 'El número de documento no es válido para el tipo seleccionado.',
            });
        }
    }
}
