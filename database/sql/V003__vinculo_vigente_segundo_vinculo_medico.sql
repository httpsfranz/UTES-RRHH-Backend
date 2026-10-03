/* ================================================================================
   V003__vinculo_vigente_segundo_vinculo_medico.sql
   Personal.VinculoLaboral: permitir el segundo vinculo del personal medico (RIT Art. 86).

   POR QUE
     UX_VinculoLaboral_Vigente impedia que un trabajador tuviera dos vinculos abiertos a la vez.
     El Art. 86 del RIT prohibe la doble percepcion, pero exceptua de forma expresa al personal
     medico (con o sin especialidad), que puede generar un segundo vinculo en la entidad, de manera
     excepcional y justificada (art. 40 de la Constitucion, Ley 32145, RM 022-2025/MINSA).
     El propio V001 indicaba eliminar el indice si la realidad admitia excepciones.

   CAMBIO
     Se elimina el indice. La regla general (un solo vinculo activo, sin superposicion de fechas)
     sigue vigente y la aplica VinculoLaboralRequest, que exceptua unicamente al personal medico.
   ================================================================================ */

IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_VinculoLaboral_Vigente' AND object_id = OBJECT_ID(N'Personal.VinculoLaboral'))
    DROP INDEX UX_VinculoLaboral_Vigente ON Personal.VinculoLaboral;
GO
