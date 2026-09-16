/* ================================================================================
   validacion_esquema.sql   -   DIAGNOSTICO DEL ESQUEMA (solo lectura)
   --------------------------------------------------------------------------------
   Este archivo NO forma parte de las migraciones. No crea ni modifica nada.
   Se ejecuta a mano en SQL Server Management Studio, contra DB_ControlAsistencia,
   cuando se quiere comprobar que el esquema quedo integro despues de un
   'php artisan migrate'.

   Estaba dentro del script original como "22. VALIDACION FINAL". Se separo porque
   devuelve resultsets, y un resultset sin consumir dentro de PDO::exec() deja un
   cursor abierto que hace fallar la siguiente consulta de la migracion.

   USO:  abrir en SSMS  ->  seleccionar DB_ControlAsistencia  ->  F5
   ================================================================================ */

USE [DB_ControlAsistencia];
GO

/* ================================================================================
   22. VALIDACION FINAL
   ================================================================================ */

/* 22.1 Inventario de tablas por esquema */
SELECT s.name AS Esquema, COUNT(*) AS Tablas
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
GROUP BY s.name
ORDER BY s.name;
GO

/* 22.2 Todas las tablas deben tener clave primaria */
SELECT s.name AS Esquema, t.name AS TablaSinPK
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE NOT EXISTS (
    SELECT 1 FROM sys.key_constraints k
    WHERE k.parent_object_id = t.object_id AND k.type = 'PK')
ORDER BY s.name, t.name;
GO

/* 22.3 Mapa completo de claves foraneas */
SELECT  OBJECT_SCHEMA_NAME(fk.parent_object_id) AS EsquemaHijo,
        OBJECT_NAME(fk.parent_object_id)        AS TablaHija,
        COL_NAME(fkc.parent_object_id, fkc.parent_column_id)         AS ColumnaHija,
        OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS EsquemaPadre,
        OBJECT_NAME(fk.referenced_object_id)        AS TablaPadre,
        COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ColumnaPadre,
        fk.name AS Restriccion
FROM sys.foreign_keys fk
INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
ORDER BY EsquemaHijo, TablaHija, Restriccion;
GO

/* 22.4 Ninguna FK debe estar deshabilitada o no confiable */
SELECT OBJECT_SCHEMA_NAME(parent_object_id) AS Esquema,
       OBJECT_NAME(parent_object_id) AS Tabla,
       name AS ForeignKey, is_disabled, is_not_trusted
FROM sys.foreign_keys
WHERE is_disabled = 1 OR is_not_trusted = 1;
GO

/* 22.5 Columnas FK sin indice de apoyo (candidatas a indexar) */
SELECT  OBJECT_SCHEMA_NAME(fk.parent_object_id) AS Esquema,
        OBJECT_NAME(fk.parent_object_id) AS Tabla,
        COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS Columna
FROM sys.foreign_keys fk
INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
WHERE NOT EXISTS (
    SELECT 1
    FROM sys.index_columns ic
    WHERE ic.object_id = fkc.parent_object_id
      AND ic.column_id = fkc.parent_column_id
      AND ic.key_ordinal = 1)
ORDER BY Esquema, Tabla, Columna;
GO

/* 22.6 Verificacion funcional del encargo */
SELECT N'Microred y EESS como tablas explicitas' AS Requisito,
       CASE WHEN OBJECT_ID(N'Organizacion.Microred', N'U') IS NOT NULL
             AND OBJECT_ID(N'Organizacion.EstablecimientoSalud', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END AS Estado
UNION ALL SELECT N'Cada EESS pertenece a una Microred',
       CASE WHEN COL_LENGTH(N'Organizacion.EstablecimientoSalud', N'MicroredId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'NO existen autorreferencias en el modelo',
       CASE WHEN NOT EXISTS (
            SELECT 1 FROM sys.foreign_keys
            WHERE parent_object_id = referenced_object_id) THEN N'OK' ELSE N'ERROR' END
UNION ALL SELECT N'NO existe tabla Red ni UnidadOrganica',
       CASE WHEN OBJECT_ID(N'Organizacion.UnidadOrganica', N'U') IS NULL
             AND OBJECT_ID(N'Organizacion.Red', N'U') IS NULL THEN N'OK' ELSE N'REVISAR' END
UNION ALL SELECT N'Un trabajador puede tener un horario',
       CASE WHEN OBJECT_ID(N'Personal.AsignacionHorario', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Un horario contiene multiples turnos',
       CASE WHEN OBJECT_ID(N'Configuracion.HorarioDetalle', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'El vinculo ya NO cuelga de un turno unico',
       CASE WHEN COL_LENGTH(N'Personal.VinculoLaboral', N'TurnoId') IS NULL THEN N'OK' ELSE N'PENDIENTE' END
UNION ALL SELECT N'Un EESS puede tener responsable con historico',
       CASE WHEN OBJECT_ID(N'Organizacion.ResponsableEess', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Condicion laboral como catalogo ampliable',
       CASE WHEN OBJECT_ID(N'Personal.CondicionLaboral', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'AIRHSP en el vinculo laboral',
       CASE WHEN COL_LENGTH(N'Personal.VinculoLaboral', N'VinculoLaboralCodigoAirhsp') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Colegiatura y tipo de colegiatura',
       CASE WHEN OBJECT_ID(N'Personal.Colegiatura', N'U') IS NOT NULL
             AND OBJECT_ID(N'Personal.ColegiaturaTipo', N'U') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Colegiatura NO esta en Personal.Cargo',
       CASE WHEN COL_LENGTH(N'Personal.Cargo', N'ColegiaturaNumero') IS NULL THEN N'OK' ELSE N'ERROR' END
UNION ALL SELECT N'Las papeletas tienen tipo',
       CASE WHEN COL_LENGTH(N'Solicitudes.Papeleta', N'TipoPapeletaId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Las faltas pueden justificarse',
       CASE WHEN COL_LENGTH(N'Asistencia.AsistenciaDiaria', N'JustificacionFaltaId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'La justificacion tiene concepto y documento',
       CASE WHEN COL_LENGTH(N'Asistencia.JustificacionFalta', N'ConceptoJustificacionId') IS NOT NULL
             AND COL_LENGTH(N'Asistencia.JustificacionFalta', N'DocumentoSustentoId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'Programacion gestionada por EESS',
       CASE WHEN COL_LENGTH(N'Programacion.ProgramacionPeriodo', N'EessId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'CargaProgramacion es documental y usa ruta externa',
       CASE WHEN OBJECT_ID(N'Programacion.CargaProgramacion', N'U') IS NOT NULL
             AND COL_LENGTH(N'Programacion.CargaProgramacion', N'DocumentoSustentoId') IS NOT NULL THEN N'OK' ELSE N'FALTA' END
UNION ALL SELECT N'CargaProgramacion no duplica ProgramacionPeriodo',
       CASE WHEN OBJECT_ID(N'Programacion.ProgramacionMensual', N'U') IS NULL THEN N'OK' ELSE N'REVISAR' END
UNION ALL SELECT N'Sin VARBINARY para documentos',
       CASE WHEN NOT EXISTS (
            SELECT 1 FROM sys.columns c
            INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
            WHERE ty.name = N'varbinary'
              AND c.object_id IN (OBJECT_ID(N'Soporte.DocumentoSustento'), OBJECT_ID(N'Programacion.CargaProgramacion'))
       ) THEN N'OK' ELSE N'ERROR' END
UNION ALL SELECT N'Tablas fusionadas eliminadas',
       CASE WHEN OBJECT_ID(N'Programacion.Reprogramacion', N'U') IS NULL
             AND OBJECT_ID(N'Programacion.CambioTurnoSolicitante', N'U') IS NULL
             AND OBJECT_ID(N'Programacion.CambioTurnoReemplazante', N'U') IS NULL
             AND OBJECT_ID(N'Solicitudes.AvisoAusencia', N'U') IS NULL
             AND OBJECT_ID(N'Solicitudes.PermisoHorario', N'U') IS NULL
             AND OBJECT_ID(N'Solicitudes.TipoPermiso', N'U') IS NULL THEN N'OK' ELSE N'REVISAR' END;
GO
