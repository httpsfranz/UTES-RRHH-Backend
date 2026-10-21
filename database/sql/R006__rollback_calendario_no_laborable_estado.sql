/* REVERSA DE V006__calendario_no_laborable_estado.sql: quita la columna de estado. */

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_CalendarioNoLaborableEstado')
    ALTER TABLE Soporte.CalendarioNoLaborable DROP CONSTRAINT DF_CalendarioNoLaborableEstado;
GO

IF COL_LENGTH(N'Soporte.CalendarioNoLaborable', N'CalendarioNoLaborableEstado') IS NOT NULL
    ALTER TABLE Soporte.CalendarioNoLaborable DROP COLUMN CalendarioNoLaborableEstado;
GO
