<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001 y V002: el script es idempotente (IF EXISTS).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V003__vinculo_vigente_segundo_vinculo_medico.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R003__rollback_vinculo_vigente_segundo_vinculo_medico.sql');
        $this->olvidarSqlFile('V003__vinculo_vigente_segundo_vinculo_medico.sql');
    }
};
