<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001 a V005: el script es idempotente (IF EXISTS).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V006__calendario_no_laborable_estado.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R006__rollback_calendario_no_laborable_estado.sql');
        $this->olvidarSqlFile('V006__calendario_no_laborable_estado.sql');
    }
};
