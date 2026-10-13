/* ================================================================================
   R004__rollback_asistencia_diaria_origen.sql
   REVERSA DE V004__asistencia_diaria_origen.sql ('php artisan migrate:rollback').
   Quita la columna AsistenciaDiariaOrigen; se pierde la marca de origen de cada fila.
   ================================================================================ */

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AsistenciaDiariaOrigen')
    ALTER TABLE Asistencia.AsistenciaDiaria DROP CONSTRAINT CK_AsistenciaDiariaOrigen;
GO

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_AsistenciaDiariaOrigen')
    ALTER TABLE Asistencia.AsistenciaDiaria DROP CONSTRAINT DF_AsistenciaDiariaOrigen;
GO

IF COL_LENGTH(N'Asistencia.AsistenciaDiaria', N'AsistenciaDiariaOrigen') IS NOT NULL
    ALTER TABLE Asistencia.AsistenciaDiaria DROP COLUMN AsistenciaDiariaOrigen;
GO
