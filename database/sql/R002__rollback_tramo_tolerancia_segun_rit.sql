/* ================================================================================
   R002__rollback_tramo_tolerancia_segun_rit.sql
   REVERSA DE V002__tramo_tolerancia_segun_rit.sql ('php artisan migrate:rollback').
   Quita las dos columnas agregadas; los datos de esas columnas se pierden.
   ================================================================================ */

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_TramoToleranciaMinutosDescuento')
    ALTER TABLE Configuracion.TramoTolerancia DROP CONSTRAINT CK_TramoToleranciaMinutosDescuento;
GO

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_TramoToleranciaEsInasistencia')
    ALTER TABLE Configuracion.TramoTolerancia DROP CONSTRAINT DF_TramoToleranciaEsInasistencia;
GO

IF COL_LENGTH(N'Configuracion.TramoTolerancia', N'TramoToleranciaEsInasistencia') IS NOT NULL
    ALTER TABLE Configuracion.TramoTolerancia DROP COLUMN TramoToleranciaEsInasistencia;
GO

IF COL_LENGTH(N'Configuracion.TramoTolerancia', N'TramoToleranciaMinutosDescuento') IS NOT NULL
    ALTER TABLE Configuracion.TramoTolerancia DROP COLUMN TramoToleranciaMinutosDescuento;
GO
