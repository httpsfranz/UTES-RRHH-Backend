<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ejecuta scripts .sql de SQL Server desde una migracion de Laravel.
 * La solucion es conservar el T-SQL como fuente de verdad y usar el sistema de
 * migraciones solo para llevar la cuenta de que
 * script se aplico en que base de datos.
 *
 * QUE HACE
 * --------
 * 1. Parte el archivo por los separadores GO. GO no es T-SQL: es un separador
 *    de lotes que solo entienden SSMS y sqlcmd. PDO no lo reconoce, y ademas
 *    sentencias como CREATE VIEW o CREATE SCHEMA deben ser la primera del lote.
 * 2. Guarda un SHA-256 del archivo en dbo.SqlScriptAplicado. Si alguien edita un
 *    script ya aplicado, la proxima migracion aborta con un mensaje explicito.
 *    Sin esto, "no edites scripts ya aplicados" seria solo un acuerdo verbal:
 *    el que lo edita ve su cambio, los demas nunca lo reciben, y nadie se entera.
 * 3. Si un lote falla, informa el numero de lote, la linea aproximada dentro del
 *    archivo y la primera sentencia del lote. En un script de 2700 lineas eso es
 *    la diferencia entre corregir en un minuto o buscar a ciegas.
 */
trait RunsSqlFile
{
    /**
     * Aplica un script de database/sql/.
     */
    protected function runSqlFile(string $archivo): void
    {
        $ruta = database_path("sql/{$archivo}");

        if (! is_file($ruta)) {
            throw new RuntimeException("No se encontro el script database/sql/{$archivo}");
        }

        $this->asegurarBitacora();

        $hash   = hash_file('sha256', $ruta);
        $previo = DB::table('SqlScriptAplicado')->where('Archivo', $archivo)->value('Checksum');

        if ($previo !== null && $previo !== $hash) {
            throw new RuntimeException(
                "\n".
                "  El script '{$archivo}' fue MODIFICADO despues de aplicarse en esta base.\n".
                "  Los scripts son inmutables: el resto del equipo ya aplico la version anterior\n".
                "  y nunca recibiria este cambio.\n\n".
                "  Que hacer: revertir '{$archivo}' a como estaba (git checkout) y poner el cambio\n".
                "  en un archivo nuevo V0XX__<descripcion>.sql con su propia migracion.\n"
            );
        }

        $this->ejecutarLotes(file_get_contents($ruta), $archivo);

        DB::table('SqlScriptAplicado')->updateOrInsert(
            ['Archivo' => $archivo],
            ['Checksum' => $hash, 'AplicadoEn' => now()]
        );
    }

    /**
     * Ejecuta un script sin registrarlo en la bitacora (para los rollback).
     */
    protected function runSqlFileSinRegistrar(string $archivo): void
    {
        $ruta = database_path("sql/{$archivo}");

        if (! is_file($ruta)) {
            throw new RuntimeException("No se encontro el script database/sql/{$archivo}");
        }

        $this->ejecutarLotes(file_get_contents($ruta), $archivo);
    }

    /**
     * Borra el rastro de un script para permitir volver a aplicarlo.
     */
    protected function olvidarSqlFile(string $archivo): void
    {
        $this->asegurarBitacora();

        DB::table('SqlScriptAplicado')->where('Archivo', $archivo)->delete();
    }

    /**
     * Parte el script por GO y ejecuta lote por lote.
     */
    private function ejecutarLotes(string $sql, string $archivo): void
    {
        // El \r? del final es para los archivos con saltos de linea de Windows:
        // tras un git clone en Windows la linea es "GO\r\n" y sin eso no separa.
        $lotes = preg_split(
            '/^[ \t]*GO[ \t]*\r?$/mi',
            $sql,
            -1,
            PREG_SPLIT_OFFSET_CAPTURE
        );

        if ($lotes === false) {
            throw new RuntimeException(
                "No se pudo separar '{$archivo}' por lotes GO: ".preg_last_error_msg()
            );
        }

        $numero = 0;

        foreach ($lotes as [$lote, $offset]) {
            if (trim($lote) === '') {
                continue;
            }

            $numero++;

            try {
                DB::unprepared($lote);
            } catch (Throwable $e) {
                $linea = substr_count(substr($sql, 0, $offset), "\n") + 1;

                throw new RuntimeException(
                    "\n".
                    "  Fallo el lote #{$numero} de '{$archivo}' (alrededor de la linea {$linea}).\n".
                    "  Sentencia: ".$this->resumenDelLote($lote)."\n".
                    "  SQL Server dijo: ".$e->getMessage()."\n",
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Crea dbo.SqlScriptAplicado si aun no existe.
     */
    private function asegurarBitacora(): void
    {
        DB::unprepared(<<<'SQL'
            IF OBJECT_ID(N'dbo.SqlScriptAplicado', N'U') IS NULL
            CREATE TABLE dbo.SqlScriptAplicado (
                Archivo    NVARCHAR(200) NOT NULL CONSTRAINT PK_SqlScriptAplicado PRIMARY KEY,
                Checksum   CHAR(64)      NOT NULL,
                AplicadoEn DATETIME2(0)  NOT NULL CONSTRAINT DF_SqlScriptAplicadoEn DEFAULT (SYSDATETIME())
            );
        SQL);
    }

    /**
     * Primera linea util del lote, para identificarlo en el mensaje de error.
     */
    private function resumenDelLote(string $lote): string
    {
        $limpio = preg_replace('#/\*.*?\*/#s', '', $lote);
        $limpio = preg_replace('/^\s*--.*$/m', '', (string) $limpio);

        foreach (preg_split('/\r?\n/', (string) $limpio) as $linea) {
            if (trim($linea) !== '') {
                return mb_strimwidth(trim($linea), 0, 120, '...');
            }
        }

        return '(lote sin sentencias legibles)';
    }
}
