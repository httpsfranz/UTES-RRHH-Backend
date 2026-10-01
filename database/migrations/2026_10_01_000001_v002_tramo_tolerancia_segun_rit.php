<?php

use App\Support\RunsSqlFile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RunsSqlFile;

    /**
     * Sin transaccion, igual que V001: el script es idempotente (IF COL_LENGTH ... IS NULL).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->runSqlFile('V002__tramo_tolerancia_segun_rit.sql');
    }

    public function down(): void
    {
        $this->runSqlFileSinRegistrar('R002__rollback_tramo_tolerancia_segun_rit.sql');
        $this->olvidarSqlFile('V002__tramo_tolerancia_segun_rit.sql');
    }
};
