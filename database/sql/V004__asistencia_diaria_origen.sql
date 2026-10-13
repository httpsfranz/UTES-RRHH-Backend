/* ================================================================================
   V004__asistencia_diaria_origen.sql
   Asistencia.AsistenciaDiaria: distinguir la fila digitada a mano de la calculada por el motor.

   POR QUE
     El motor de calculo (CalculoAsistenciaService) recalcula la asistencia desde las marcaciones y
     la programacion, pero no debe pisar lo que RRHH digito o corrigio a mano. AsistenciaDiariaFechaProceso
     no sirve para distinguirlas: tambien la llenan la API y los datos de prueba.

   CAMBIO
     Se agrega AsistenciaDiariaOrigen: MANUAL (por omision, incluye las filas existentes) o CALCULADO.
   ================================================================================ */

IF COL_LENGTH(N'Asistencia.AsistenciaDiaria', N'AsistenciaDiariaOrigen') IS NULL
    ALTER TABLE Asistencia.AsistenciaDiaria
        ADD AsistenciaDiariaOrigen NVARCHAR(20) NOT NULL
            CONSTRAINT DF_AsistenciaDiariaOrigen DEFAULT (N'MANUAL');
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AsistenciaDiariaOrigen')
    ALTER TABLE Asistencia.AsistenciaDiaria
        ADD CONSTRAINT CK_AsistenciaDiariaOrigen CHECK (AsistenciaDiariaOrigen IN (N'MANUAL', N'CALCULADO'));
GO
