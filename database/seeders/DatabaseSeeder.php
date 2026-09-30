<?php

namespace Database\Seeders;

use App\Support\VaciaEsquemasDelSistema;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * SEEDER MAESTRO. Devuelve la base de datos a un estado inicial conocido:
 *
 *   1. Vacia TODAS las tablas de los 13 esquemas del sistema (conserva la estructura,
 *      respeta FK y reinicia los Id a 1) -> App\Support\VaciaEsquemasDelSistema.
 *   2. Siembra los catalogos base                -> CatalogosSeeder.
 *   3. Siembra datos de prueba para Nivel 0      -> DatosPruebaSeeder.
 *
 * Es reproducible: cada ejecucion termina en exactamente el mismo estado, sin importar
 * cuantas pruebas se hicieron antes.
 *
 *     php artisan db:seed --class=DatabaseSeeder      (o simplemente: php artisan db:seed)
 *     php artisan migrate:fresh --seed                 (recrea ademas la estructura)
 *
 * DESTRUCTIVO: borra datos reales. Se niega a correr en produccion; alli se usa
 * unicamente   php artisan db:seed --class=CatalogosSeeder   (idempotente, no borra).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DatabaseSeeder borra todos los datos y no puede correr en produccion. '
                .'Para cargar los catalogos use: php artisan db:seed --class=CatalogosSeeder'
            );
        }

        $tablas = VaciaEsquemasDelSistema::ejecutar();
        $this->command?->info("Datos borrados: {$tablas} tablas vaciadas, identidades reiniciadas.");

        $this->call([
            CatalogosSeeder::class,
            DatosPruebaSeeder::class,
        ]);
    }
}
