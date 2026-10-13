<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001 a V003: el script es idempotente (IF EXISTS).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V004__asistencia_diaria_origen.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R004__rollback_asistencia_diaria_origen.sql');
        $this->olvidarSqlFile('V004__asistencia_diaria_origen.sql');
    }
};
