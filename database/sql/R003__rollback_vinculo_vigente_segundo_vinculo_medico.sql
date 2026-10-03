/* ================================================================================
   R003__rollback_vinculo_vigente_segundo_vinculo_medico.sql
   REVERSA DE V003 ('php artisan migrate:rollback'). Recrea UX_VinculoLaboral_Vigente.
   Falla si ya existen trabajadores con dos vinculos abiertos (p. ej. un segundo vinculo medico).
   ================================================================================ */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_VinculoLaboral_Vigente' AND object_id = OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE UNIQUE INDEX UX_VinculoLaboral_Vigente ON Personal.VinculoLaboral(TrabajadorId)
    WHERE VinculoLaboralFechaFin IS NULL AND VinculoLaboralEstado = 1;
GO
