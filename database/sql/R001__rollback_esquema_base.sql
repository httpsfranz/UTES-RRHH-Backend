/* ================================================================================
   R001__rollback_esquema_base.sql
   REVERSA DE V001__esquema_base.sql
   --------------------------------------------------------------------------------
   Se ejecuta cuando alguien corre 'php artisan migrate:rollback'.

   BORRA TODOS LOS DATOS. Actua unicamente sobre los 13 esquemas del sistema:
   no toca 'dbo', asi que la tabla 'migrations' de Laravel, 'SqlScriptAplicado',
   'jobs', 'cache' y 'sessions' quedan intactas.

   Orden obligatorio:
     1. Claves foraneas   (si no, no se puede borrar ninguna tabla)
     2. Vistas
     3. Tablas
     4. Esquemas          (un esquema no se borra si aun contiene objetos)

   Todo es dinamico: no hay lista de tablas que mantener a mano. Si en V0XX se
   agrega una tabla nueva dentro de estos esquemas, este rollback la borra sola.
   ================================================================================ */

DECLARE @esquemas TABLE (Nombre SYSNAME PRIMARY KEY);

INSERT INTO @esquemas (Nombre) VALUES
    (N'Organizacion'), (N'Personal'),      (N'Seguridad'),
    (N'Biometria'),    (N'Configuracion'), (N'Programacion'),
    (N'Asistencia'),   (N'Solicitudes'),   (N'Soporte'),
    (N'Vacaciones'),   (N'Compensaciones'),(N'Consolidacion'),
    (N'Disciplina');

DECLARE @sql NVARCHAR(MAX);

/* ---- 1. Claves foraneas ---- */
SET @sql = N'';
SELECT @sql = @sql + N'ALTER TABLE ' + QUOTENAME(s.name) + N'.' + QUOTENAME(t.name)
                   + N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM sys.foreign_keys fk
INNER JOIN sys.tables  t ON t.object_id  = fk.parent_object_id
INNER JOIN sys.schemas s ON s.schema_id  = t.schema_id
WHERE s.name IN (SELECT Nombre FROM @esquemas);
IF @sql <> N'' EXEC sys.sp_executesql @sql;

/* ---- 2. Vistas ---- */
SET @sql = N'';
SELECT @sql = @sql + N'DROP VIEW ' + QUOTENAME(s.name) + N'.' + QUOTENAME(v.name) + N';' + CHAR(10)
FROM sys.views v
INNER JOIN sys.schemas s ON s.schema_id = v.schema_id
WHERE s.name IN (SELECT Nombre FROM @esquemas);
IF @sql <> N'' EXEC sys.sp_executesql @sql;

/* ---- 3. Tablas ---- */
SET @sql = N'';
SELECT @sql = @sql + N'DROP TABLE ' + QUOTENAME(s.name) + N'.' + QUOTENAME(t.name) + N';' + CHAR(10)
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE s.name IN (SELECT Nombre FROM @esquemas);
IF @sql <> N'' EXEC sys.sp_executesql @sql;

/* ---- 4. Esquemas ---- */
SET @sql = N'';
SELECT @sql = @sql + N'DROP SCHEMA ' + QUOTENAME(s.name) + N';' + CHAR(10)
FROM sys.schemas s
WHERE s.name IN (SELECT Nombre FROM @esquemas);
IF @sql <> N'' EXEC sys.sp_executesql @sql;
GO
