<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001 a V004: el script es idempotente (IF EXISTS).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V005__seguridad_notificaciones_y_flujos.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R005__rollback_seguridad_notificaciones_y_flujos.sql');
        $this->olvidarSqlFile('V005__seguridad_notificaciones_y_flujos.sql');
    }
};
