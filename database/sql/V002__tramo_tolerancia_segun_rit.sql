/* ================================================================================
   V002__tramo_tolerancia_segun_rit.sql
   Configuracion.TramoTolerancia: representar la escala real del Art. 22 del RIT.

   POR QUE
     El Art. 22 del Reglamento Interno de Trabajo no cobra "minutos x factor": cada tramo
     de tardanza equivale a un descuento FIJO de minutos, y pasado el minuto 30 la tardanza
     se convierte en inasistencia injustificada:

         minutos tarde   1 - 5      tolerancia, sin descuento
                         6 - 10     descuento equivalente a 10 minutos
                        11 - 20     descuento equivalente a 20 minutos
                        21 - 30     descuento equivalente a 30 minutos
                        31 en adelante   inasistencia injustificada

     La misma escala rige para la sede administrativa, turno manana, turno tarde y guardias
     (diurna y nocturna): se mide en minutos DESDE LA HORA DE INGRESO del turno, no en hora
     de reloj. Con solo TramoToleranciaFactorDescuento no se puede expresar un descuento
     fijo ni "esto ya es inasistencia", asi que se agregan dos columnas.

   CAMBIO (aditivo, no rompe nada existente)
     - TramoToleranciaMinutosDescuento : minutos equivalentes que se descuentan (NULL = usar el factor).
     - TramoToleranciaEsInasistencia   : 1 = a partir de este tramo se considera inasistencia.
   ================================================================================ */

IF COL_LENGTH(N'Configuracion.TramoTolerancia', N'TramoToleranciaMinutosDescuento') IS NULL
    ALTER TABLE Configuracion.TramoTolerancia
        ADD TramoToleranciaMinutosDescuento INT NULL;
GO

IF COL_LENGTH(N'Configuracion.TramoTolerancia', N'TramoToleranciaEsInasistencia') IS NULL
    ALTER TABLE Configuracion.TramoTolerancia
        ADD TramoToleranciaEsInasistencia BIT NOT NULL
            CONSTRAINT DF_TramoToleranciaEsInasistencia DEFAULT (0);
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_TramoToleranciaMinutosDescuento')
    ALTER TABLE Configuracion.TramoTolerancia
        ADD CONSTRAINT CK_TramoToleranciaMinutosDescuento
            CHECK (TramoToleranciaMinutosDescuento IS NULL OR TramoToleranciaMinutosDescuento >= 0);
GO
