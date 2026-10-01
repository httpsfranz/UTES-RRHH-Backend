<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numero de documento de identidad segun el tipo (Personal.TipoDocumentoIdentidad).
 *
 *   DNI        exactamente 8 digitos.
 *   CE, PTP    solo digitos, de 9 hasta TipoDocumentoIdentidadLongitud (12 por defecto).
 *   resto      (pasaporte, etc.) letras y numeros, de 6 hasta TipoDocumentoIdentidadLongitud (20 por defecto).
 *
 * $longitud es el maximo que declara el catalogo; si el tipo no la define se usa el tope por defecto.
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
        $tipo = strtoupper($this->codigoTipo);

        $valido = match ($tipo) {
            'DNI' => preg_match('/^\d{8}$/D', $numero) === 1,
            'CE', 'PTP' => preg_match('/^\d{9,'.max(9, $this->longitud ?? 12).'}$/D', $numero) === 1,
            default => preg_match('/^[A-Za-z0-9]{6,'.max(6, $this->longitud ?? 20).'}$/D', $numero) === 1,
        };

        if (! $valido) {
            $fail(match ($tipo) {
                'DNI' => 'El DNI debe tener exactamente 8 dígitos numéricos.',
                'CE', 'PTP' => 'El número de documento debe tener solo dígitos (entre 9 y '.max(9, $this->longitud ?? 12).').',
                default => 'El número de documento debe ser alfanumérico (entre 6 y '.max(6, $this->longitud ?? 20).' caracteres).',
            });
        }
    }
}
