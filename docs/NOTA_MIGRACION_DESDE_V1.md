# Nota de migracion desde una base v1 ya poblada

Extraido del script original. Aplica solo si existe una `DB_ControlAsistencia` v1 con datos reales que haya que conservar. Para un arranque desde cero (el caso del equipo hoy) esta nota no se usa.

```
/* ================================================================================
   FIN DEL SCRIPT
   --------------------------------------------------------------------------------
   NOTA SOBRE MIGRACION DE UNA BASE YA POBLADA
   Este script crea la estructura desde cero. Si ya existe DB_ControlAsistencia v1
   con datos, el orden sugerido de migracion es:

     1. Respaldar la base completa.
     2. Crear las tablas nuevas y las columnas nuevas (este script las crea solo si
        no existen; para columnas en tablas existentes use ALTER TABLE ... ADD).
     3. Cargar Organizacion.Microred (un registro por distrito) y luego
        Organizacion.EstablecimientoSalud indicando el MicroredId de cada EESS.
     4. Migrar Personal.VinculoLaboral.TurnoId:
          - crear un Horario por cada combinacion distinta de turno utilizada;
          - insertar la AsignacionHorario correspondiente a cada vinculo;
          - recien entonces eliminar la columna TurnoId.
     5. Migrar Solicitudes.TipoPermiso  -> Solicitudes.TipoPapeleta.
     6. Migrar Solicitudes.PermisoHorario -> Solicitudes.Papeleta.
     7. Migrar Solicitudes.PapeletaSalida -> Solicitudes.Papeleta (asignando el
        TipoPapeletaId que corresponda a cada MotivoPapeletaId).
     8. Migrar Solicitudes.AvisoAusencia -> Asistencia.JustificacionFalta
        (mapeando el texto del motivo a un ConceptoJustificacionId).
     9. Migrar Programacion.ProgramacionMensual -> ProgramacionPeriodo + ProgramacionTrabajador
        (agrupando por EESS, anio y mes para generar las cabeceras).
    10. Migrar Reprogramacion / CambioTurnoSolicitante / CambioTurnoReemplazante
        -> Programacion.CambioTurno con su TipoCambioTurnoId.
    11. Verificar con la seccion 22 y solo entonces eliminar las tablas antiguas.
   ================================================================================ */
```
