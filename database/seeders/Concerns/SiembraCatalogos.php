<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Utilidades comunes de los seeders de datos: inserciones idempotentes por codigo y
 * resolucion de claves foraneas a partir del codigo de la fila referenciada.
 */
trait SiembraCatalogos
{
    /**
     * Inserta solo las filas cuyo valor de $columnaClave aun no existe en la tabla.
     * Las columnas que se omiten toman el DEFAULT definido en el esquema (Estado = 1, etc.).
     *
     * @param  list<array<string,mixed>>  $filas
     */
    protected function sembrar(string $tabla, string $columnaClave, array $filas): void
    {
        $existentes = DB::table($tabla)->pluck($columnaClave)->map(fn ($v) => (string) $v)->all();

        $nuevas = array_values(array_filter(
            $filas,
            fn (array $fila) => ! in_array((string) $fila[$columnaClave], $existentes, true),
        ));

        // Fila por fila: cada una puede traer columnas distintas (p. ej. una con Estado = 0) y
        // asi los Id se asignan en el mismo orden en que se declaran las filas.
        foreach ($nuevas as $fila) {
            DB::table($tabla)->insert($fila);
        }
    }

    /**
     * Mapa codigo => id de una tabla, para resolver claves foraneas.
     *
     * @return array<string,int>
     */
    protected function ids(string $tabla, string $columnaCodigo, string $columnaId): array
    {
        return DB::table($tabla)->pluck($columnaId, $columnaCodigo)->map(fn ($id) => (int) $id)->all();
    }
}
