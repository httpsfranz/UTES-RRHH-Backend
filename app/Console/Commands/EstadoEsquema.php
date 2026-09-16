<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
 * Compara los archivos V*.sql del repositorio contra lo realmente aplicado en
 * ESTA maquina, y avisa si alguno fue editado despues de aplicarse.
 */
class EstadoEsquema extends Command
{
    protected $signature = 'sql:estado';

    protected $description = 'Muestra que scripts de database/sql estan aplicados en esta base de datos';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('No hay conexion a SQL Server: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Base de datos : <fg=cyan>'.DB::connection()->getDatabaseName().'</>');
        $this->line('  Servidor      : <fg=cyan>'.config('database.connections.sqlsrv.host').'</>');
        $this->line('');

        $aplicados = [];

        if (DB::getSchemaBuilder()->hasTable('SqlScriptAplicado')) {
            $aplicados = DB::table('SqlScriptAplicado')->get()->keyBy('Archivo');
        }

        $archivos = glob(database_path('sql/V*.sql')) ?: [];
        sort($archivos);

        if ($archivos === []) {
            $this->warn('  No hay scripts V*.sql en database/sql/');

            return self::SUCCESS;
        }

        $filas    = [];
        $pendientes = 0;
        $alterados  = 0;

        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            $hash   = hash_file('sha256', $ruta);
            $fila   = $aplicados[$nombre] ?? null;

            if ($fila === null) {
                $pendientes++;
                $estado = '<fg=yellow>PENDIENTE</>';
                $cuando = '—';
            } elseif ($fila->Checksum !== $hash) {
                $alterados++;
                $estado = '<fg=red>ALTERADO</>';
                $cuando = $fila->AplicadoEn;
            } else {
                $estado = '<fg=green>aplicado</>';
                $cuando = $fila->AplicadoEn;
            }

            $filas[] = [$nombre, $estado, $cuando, substr($hash, 0, 12)];
        }

        $this->table(['Script', 'Estado', 'Aplicado el', 'SHA-256'], $filas);

        if ($alterados > 0) {
            $this->error('  '.$alterados.' script(s) fueron editados despues de aplicarse.');
            $this->line('  Revertirlos con git y poner el cambio en un V0XX nuevo.');

            return self::FAILURE;
        }

        if ($pendientes > 0) {
            $this->warn('  '.$pendientes.' script(s) pendientes. Corre: php artisan migrate');

            return self::SUCCESS;
        }

        $this->info('  Esquema al dia.');

        return self::SUCCESS;
    }
}
