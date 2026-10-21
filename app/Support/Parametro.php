<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lectura de Configuracion.ParametroSistema: el valor vigente (activo) de un parametro o el valor por omision si no
 * existe. Se lee en cada uso para que un cambio en la pantalla de parametros se aplique de inmediato.
 */
class Parametro
{
    public static function texto(string $codigo, ?string $porDefecto = null): ?string
    {
        $valor = DB::table('Configuracion.ParametroSistema')->where('ParametroSistemaCodigo', $codigo)->where('ParametroSistemaEstado', 1)->value('ParametroSistemaValor');

        return $valor === null || $valor === '' ? $porDefecto : (string) $valor;
    }

    public static function entero(string $codigo, int $porDefecto): int
    {
        $valor = self::texto($codigo);

        return is_numeric($valor) ? (int) $valor : $porDefecto;
    }
}
