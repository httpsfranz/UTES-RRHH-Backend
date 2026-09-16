<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Las migraciones de este proyecto NO corren dentro de una transaccion.
     *
     * Laravel envuelve las migraciones en transaccion cuando el motor soporta DDL
     * transaccional, y SQL Server lo soporta. Aqui no conviene:
     *
     *   - V001 son ~125 lotes y 60+ tablas; una sola transaccion mantiene bloqueos
     *     sobre todo el catalogo del sistema mientras dure.
     *   - DB::unprepared con multiples lotes dentro de una transaccion abierta da
     *     problemas con el driver sqlsrv segun la version del ODBC Driver.
     *   - No hace falta: el script es idempotente. Si falla a la mitad, se corrige
     *     el error y se vuelve a correr 'php artisan migrate'; lo ya creado se
     *     salta solo y sigue donde quedo.
     *
     * El checksum se registra RECIEN al terminar bien, asi que una corrida a medias
     * nunca queda marcada como aplicada.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V001__esquema_base.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R001__rollback_esquema_base.sql');
        $this->olvidarSqlFile('V001__esquema_base.sql');
    }
};
