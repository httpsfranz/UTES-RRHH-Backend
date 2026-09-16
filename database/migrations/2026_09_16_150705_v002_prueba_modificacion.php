<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\RunsSqlFile;

return new class extends Migration

{

    use RunsSqlFile;

    public $withinTransaction = false;

    public function up(): void

    {

        $this->runSqlFile('V002__prueba_modificacion.sql');

    }

    public function down(): void

    {

        $this->runSqlFileSinRegistrar('R002__rollback_prueba_modificacion.sql');

        $this->olvidarSqlFile('V002__prueba_modificacion.sql');

    }

};
