<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001 a V006: el script es idempotente (IF EXISTS).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V007__reglas_rit_y_salida_a_produccion.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R007__rollback_reglas_rit_y_salida_a_produccion.sql');
        $this->olvidarSqlFile('V007__reglas_rit_y_salida_a_produccion.sql');
    }
};
