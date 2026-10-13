<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
 * Crea (si hace falta) y deja lista la base de datos de PHPUnit: la estructura de los scripts V*.sql y SOLO los casos
 * puntuales de los seeders, sin la expansion de volumen. Asi las pruebas verifican siempre el mismo estado inicial y no
 * dependen de lo que haya en la base de trabajo, que si lleva el volumen (php artisan db:seed).
 */
class PrepararBaseDePruebas extends Command
{
    protected $signature = 'pruebas:preparar';

    protected $description = 'Crea la base de datos de PHPUnit (si no existe) y la siembra solo con los casos puntuales, sin volumen';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('No se prepara la base de pruebas en produccion.');

            return self::FAILURE;
        }

        $base = (string) config('seed.base_de_pruebas');
        $trabajo = (string) config('database.connections.sqlsrv.database');
        if ($base === '' || strcasecmp($base, $trabajo) === 0) {
            $this->error("La base de pruebas ({$base}) no puede ser la base de trabajo ({$trabajo}): se borraria todo su contenido.");

            return self::FAILURE;
        }

        try {
            $this->crearSiNoExiste($base);
            $this->usarBase($base);

            $this->components->info("Estructura y datos de {$base} (sin volumen)...");
            // migrate:fresh deja la estructura limpia; DatabaseSeeder vacia y siembra catalogos y casos puntuales.
            config(['seed.volumen' => false]);
            $this->call('migrate:fresh', ['--force' => true]);
            $this->call('db:seed', ['--force' => true]);
        } catch (Throwable $e) {
            $this->error('No se pudo preparar la base de pruebas: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->usarBase($trabajo);
        }

        $this->components->info("Lista: PHPUnit usa {$base}. La base de trabajo ({$trabajo}) no se toco.");

        return self::SUCCESS;
    }

    private function crearSiNoExiste(string $base): void
    {
        $this->usarBase('master');
        if (DB::selectOne('SELECT DB_ID(?) AS id', [$base])->id === null) {
            $this->components->info("Creando la base {$base}...");
            DB::statement('CREATE DATABASE ['.str_replace(']', ']]', $base).']');
        }
    }

    private function usarBase(string $nombre): void
    {
        config(['database.connections.sqlsrv.database' => $nombre]);
        DB::purge('sqlsrv');
    }
}
