<?php

namespace Tests\Unit;

use App\Rules\Codigo;
use App\Rules\DocumentoIdentidad;
use App\Rules\NombrePersona;
use App\Rules\TelefonoPeruano;
use App\Rules\Ubigeo;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReglasDeValidacionTest extends TestCase
{
    private function pasa(ValidationRule $regla, mixed $valor): bool
    {
        $fallo = false;
        $regla->validate('campo', $valor, function () use (&$fallo) {
            $fallo = true;
        });

        return ! $fallo;
    }

    /**
     * @return array<string,array{0: ValidationRule, 1: mixed, 2: bool}>
     */
    public static function casos(): array
    {
        return [
            'telefono 9 digitos' => [new TelefonoPeruano, '987654321', true],
            'telefono fijo 9 digitos' => [new TelefonoPeruano, '044123456', true],
            'telefono como entero' => [new TelefonoPeruano, 987654321, true],
            'telefono con letras' => [new TelefonoPeruano, 'abcdefghi', false],
            'telefono 8 digitos' => [new TelefonoPeruano, '98765432', false],
            'telefono 10 digitos' => [new TelefonoPeruano, '9876543210', false],
            'telefono con prefijo' => [new TelefonoPeruano, '+51987654321', false],
            'telefono con espacios' => [new TelefonoPeruano, '987 654 321', false],
            'telefono con guion' => [new TelefonoPeruano, '987-654-32', false],
            'telefono con salto de linea final' => [new TelefonoPeruano, "987654321\n", false],
            'telefono arreglo' => [new TelefonoPeruano, ['987654321'], false],

            'ubigeo 6 digitos' => [new Ubigeo, '130101', true],
            'ubigeo con letra' => [new Ubigeo, '13A101', false],
            'ubigeo corto' => [new Ubigeo, '1301', false],
            'ubigeo largo' => [new Ubigeo, '1301011', false],

            'codigo con guion' => [new Codigo, 'MR-LE', true],
            'codigo con punto y dos puntos' => [new Codigo, 'asistencia.ver:leer', true],
            'codigo con guion bajo' => [new Codigo, 'ZZ_TEST', true],
            'codigo con espacio' => [new Codigo, 'MR LE', false],
            'codigo empieza con guion' => [new Codigo, '-MR', false],
            'codigo con tilde' => [new Codigo, 'MÉDICO', false],
            'codigo con salto de linea final' => [new Codigo, "MR\n", false],

            'DNI 8 digitos' => [new DocumentoIdentidad('DNI'), '12345678', true],
            'DNI con letras' => [new DocumentoIdentidad('DNI'), '1234567A', false],
            'DNI 7 digitos' => [new DocumentoIdentidad('DNI'), '1234567', false],
            'DNI 9 digitos' => [new DocumentoIdentidad('DNI'), '123456789', false],
            'CE con longitud exacta' => [new DocumentoIdentidad('CE', 9), '123456789', true],
            'CE con longitud distinta' => [new DocumentoIdentidad('CE', 9), '12345678', false],
            'CE con letras' => [new DocumentoIdentidad('CE', 9), '12345678A', false],
            'pasaporte alfanumerico' => [new DocumentoIdentidad('PAS', 12), 'AB123456', true],
            'pasaporte demasiado largo' => [new DocumentoIdentidad('PAS', 12), 'AB1234567890123', false],
            'pasaporte con simbolos' => [new DocumentoIdentidad('PAS', 12), 'AB-1234', false],
            'pasaporte muy corto' => [new DocumentoIdentidad('PAS', 12), 'AB12', false],
            'CE de 12 digitos' => [new DocumentoIdentidad('CE', 12), '123456789012', true],
            'CE de 13 digitos' => [new DocumentoIdentidad('CE', 12), '1234567890123', false],
            'CE sin longitud declarada' => [new DocumentoIdentidad('CE'), '123456789', true],
            'tipo desconocido alfanumerico' => [new DocumentoIdentidad('XYZ'), 'ABC12345', true],

            'nombre simple' => [new NombrePersona, 'Ana', true],
            'nombre con tilde y enie' => [new NombrePersona, 'Muñoz Núñez', true],
            'nombre con apostrofe' => [new NombrePersona, "D'Angelo", true],
            'apellido con guion' => [new NombrePersona, 'Pérez-Castro', true],
            'nombre con abreviatura' => [new NombrePersona, 'Ma. Elena', true],
            'nombre con digito' => [new NombrePersona, 'Juan2', false],
            'nombre con etiqueta' => [new NombrePersona, '<script>', false],
            'nombre que empieza con espacio' => [new NombrePersona, ' Ana', false],
            'nombre vacio' => [new NombrePersona, '', false],
        ];
    }

    #[DataProvider('casos')]
    public function test_regla(ValidationRule $regla, mixed $valor, bool $esperado): void
    {
        $this->assertSame($esperado, $this->pasa($regla, $valor));
    }
}
