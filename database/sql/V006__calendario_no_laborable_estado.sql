/* ================================================================================
   V006__calendario_no_laborable_estado.sql
   Soporte.CalendarioNoLaborable: activar o desactivar un dia sin borrarlo.

   POR QUE
     Un dia no laborable puede quedar sin efecto (p. ej. el decreto se deroga) y debe poder desactivarse conservando
     el registro y su norma de sustento. Inactivo, el motor de asistencia y los calendarios ya no lo consideran.
   ================================================================================ */

IF COL_LENGTH(N'Soporte.CalendarioNoLaborable', N'CalendarioNoLaborableEstado') IS NULL
    ALTER TABLE Soporte.CalendarioNoLaborable
        ADD CalendarioNoLaborableEstado BIT NOT NULL CONSTRAINT DF_CalendarioNoLaborableEstado DEFAULT (1);
GO
