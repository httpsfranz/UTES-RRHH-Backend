<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Deja vacias todas las tablas de los 13 esquemas del sistema SIN tocar la estructura
 * ni las tablas de Laravel en 'dbo' (migrations, SqlScriptAplicado, cache, jobs...).
 *
 * Estrategia para SQL Server:
 *   1. NOCHECK CONSTRAINT ALL: las FK no se evaluan, asi el orden de borrado da igual
 *      (TRUNCATE no sirve: SQL Server lo prohibe en tablas referenciadas por una FK).
 *   2. DELETE FROM en cada tabla.
 *   3. DBCC CHECKIDENT ... RESEED 0 solo en tablas que ya generaron identidades,
 *      para que la siguiente fila vuelva a ser Id = 1 (estado inicial reproducible).
 *      En una tabla que nunca inserto, un RESEED 0 haria que el primer Id fuera 0.
 *   4. WITH CHECK CHECK CONSTRAINT ALL: las FK vuelven a quedar habilitadas y
 *      confiables (is_not_trusted = 0), y los seeders insertan con FK activas.
 *
 * Nombres dinamicos, sin lista de tablas que mantener: una tabla nueva en un esquema
 * del sistema queda cubierta sola (igual que R001__rollback_esquema_base.sql).
 */
class VaciaEsquemasDelSistema
{
    /** @var list<string> */
    public const ESQUEMAS = [
        'Organizacion', 'Personal', 'Seguridad', 'Biometria', 'Configuracion', 'Programacion',
        'Asistencia', 'Solicitudes', 'Soporte', 'Vacaciones', 'Compensaciones', 'Consolidacion', 'Disciplina',
    ];

    /**
     * @return int cantidad de tablas vaciadas
     */
    public static function ejecutar(): int
    {
        $esquemas = "'".implode("','", self::ESQUEMAS)."'";

        $tablas = DB::select(<<<SQL
            SELECT s.name AS esquema, t.name AS tabla
            FROM sys.tables t
            INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
            WHERE s.name IN ({$esquemas})
            SQL);

        DB::unprepared(self::porCadaTabla($tablas, 'ALTER TABLE %s NOCHECK CONSTRAINT ALL;'));
        DB::unprepared(self::porCadaTabla($tablas, 'DELETE FROM %s;'));

        $conIdentidad = DB::select(<<<SQL
            SELECT s.name AS esquema, t.name AS tabla
            FROM sys.tables t
            INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
            INNER JOIN sys.identity_columns ic ON ic.object_id = t.object_id
            WHERE s.name IN ({$esquemas}) AND ic.last_value IS NOT NULL
            SQL);

        if ($conIdentidad !== []) {
            DB::unprepared(self::porCadaTabla($conIdentidad, "DBCC CHECKIDENT ('%s', RESEED, 0) WITH NO_INFOMSGS;", true));
        }

        DB::unprepared(self::porCadaTabla($tablas, 'ALTER TABLE %s WITH CHECK CHECK CONSTRAINT ALL;'));

        return count($tablas);
    }

    /**
     * @param  list<object>  $tablas
     */
    private static function porCadaTabla(array $tablas, string $plantilla, bool $sinCorchetes = false): string
    {
        return implode("\n", array_map(function (object $t) use ($plantilla, $sinCorchetes) {
            $nombre = $sinCorchetes ? "{$t->esquema}.{$t->tabla}" : "[{$t->esquema}].[{$t->tabla}]";

            return sprintf($plantilla, $nombre);
        }, $tablas));
    }
}
