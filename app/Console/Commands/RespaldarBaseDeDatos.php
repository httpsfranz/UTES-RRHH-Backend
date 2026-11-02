<?php

namespace App\Console\Commands;

use App\Support\HoraLocal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Respaldo diario (RIT, Art. 14: custodia y conservacion de la informacion). La copia la escribe SQL Server en su propia
 * carpeta de respaldos o en RESPALDO_DIRECTORIO, y se borran las que pasan de RESPALDO_DIAS.
 */
class RespaldarBaseDeDatos extends Command
{
    protected $signature = 'sistema:respaldar';

    protected $description = 'Respalda la base de datos en la carpeta de SQL Server y elimina los respaldos antiguos';

    public function handle(): int
    {
        $base = (string) config('database.connections.sqlsrv.database');
        $carpeta = rtrim((string) (config('rit.respaldo_directorio') ?: DB::selectOne("SELECT CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400)) AS ruta")->ruta), '\\/');
        $ruta = $carpeta.DIRECTORY_SEPARATOR.$base.'_'.HoraLocal::ahora()->format('Y-m-d_His').'.bak';

        DB::unprepared("BACKUP DATABASE [{$base}] TO DISK = N'".str_replace("'", "''", $ruta)."' WITH COPY_ONLY, INIT, NAME = N'Respaldo automatico'");
        $this->info("Respaldo: {$ruta}");

        $dias = (int) config('rit.respaldo_dias');
        $limite = HoraLocal::ahora()->subDays($dias)->format('Y-m-d\TH:i:s');
        DB::unprepared("EXEC master.dbo.xp_delete_file 0, N'".str_replace("'", "''", $carpeta)."\\', N'bak', N'{$limite}', 0");

        return self::SUCCESS;
    }
}
