/* ================================================================================
   R005__rollback_seguridad_notificaciones_y_flujos.sql
   REVERSA DE V005__seguridad_notificaciones_y_flujos.sql ('php artisan migrate:rollback').
   Quita las columnas, la tabla Soporte.TipoNotificacion y el trigger de la auditoria; se pierden esos datos.
   ================================================================================ */

DROP TRIGGER IF EXISTS Seguridad.TR_Auditoria_Inmutable;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_ExpedientePad_Supervision')
    ALTER TABLE Disciplina.ExpedientePad DROP CONSTRAINT FK_ExpedientePad_Supervision;
GO
IF COL_LENGTH(N'Disciplina.ExpedientePad', N'SupervisionInopinadaId') IS NOT NULL
    ALTER TABLE Disciplina.ExpedientePad DROP COLUMN SupervisionInopinadaId;
GO

IF COL_LENGTH(N'Compensaciones.CompensacionHoraria', N'CompensacionHorariaFechaTrabajo') IS NOT NULL
    ALTER TABLE Compensaciones.CompensacionHoraria DROP COLUMN CompensacionHorariaFechaTrabajo;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_ConsolidadoAsistencia_RevisadoPor')
    ALTER TABLE Consolidacion.ConsolidadoAsistencia DROP CONSTRAINT FK_ConsolidadoAsistencia_RevisadoPor;
GO
IF COL_LENGTH(N'Consolidacion.ConsolidadoAsistencia', N'ConsolidadoAsistenciaObservacion') IS NOT NULL
    ALTER TABLE Consolidacion.ConsolidadoAsistencia DROP COLUMN ConsolidadoAsistenciaObservacion, ConsolidadoAsistenciaRevisadoPor, ConsolidadoAsistenciaFechaRevision;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_PeriodoAsistencia_CerradoPor')
    ALTER TABLE Consolidacion.PeriodoAsistencia DROP CONSTRAINT FK_PeriodoAsistencia_CerradoPor;
GO
IF COL_LENGTH(N'Consolidacion.PeriodoAsistencia', N'PeriodoAsistenciaCerradoPor') IS NOT NULL
    ALTER TABLE Consolidacion.PeriodoAsistencia DROP COLUMN PeriodoAsistenciaCerradoPor;
GO

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_NotificacionCorreoEstado')
    ALTER TABLE Soporte.Notificacion DROP CONSTRAINT CK_NotificacionCorreoEstado;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_NotificacionCorreoEstado')
    ALTER TABLE Soporte.Notificacion DROP CONSTRAINT DF_NotificacionCorreoEstado;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_NotificacionCorreoIntentos')
    ALTER TABLE Soporte.Notificacion DROP CONSTRAINT DF_NotificacionCorreoIntentos;
GO
IF COL_LENGTH(N'Soporte.Notificacion', N'NotificacionFechaLectura') IS NOT NULL
    ALTER TABLE Soporte.Notificacion DROP COLUMN NotificacionFechaLectura, NotificacionCorreoEstado, NotificacionCorreoIntentos, NotificacionCorreoFechaEnvio, NotificacionCorreoError;
GO

DROP TABLE IF EXISTS Soporte.TipoNotificacion;
GO

IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Auditoria_FechaHora')
    DROP INDEX IX_Auditoria_FechaHora ON Seguridad.Auditoria;
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AuditoriaOperacion')
    ALTER TABLE Seguridad.Auditoria DROP CONSTRAINT CK_AuditoriaOperacion;
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AuditoriaResultado')
    ALTER TABLE Seguridad.Auditoria DROP CONSTRAINT CK_AuditoriaResultado;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_AuditoriaResultado')
    ALTER TABLE Seguridad.Auditoria DROP CONSTRAINT DF_AuditoriaResultado;
GO
IF COL_LENGTH(N'Seguridad.Auditoria', N'AuditoriaResultado') IS NOT NULL
    ALTER TABLE Seguridad.Auditoria DROP COLUMN AuditoriaResultado, AuditoriaModulo, AuditoriaDetalle;
GO
DELETE FROM Seguridad.Auditoria WHERE AuditoriaOperacion NOT IN (N'INSERT', N'UPDATE', N'DELETE');
ALTER TABLE Seguridad.Auditoria ADD CONSTRAINT CK_AuditoriaOperacion CHECK (AuditoriaOperacion IN (N'INSERT', N'UPDATE', N'DELETE'));
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_UsuarioRol_AsignadoPor')
    ALTER TABLE Seguridad.UsuarioRol DROP CONSTRAINT FK_UsuarioRol_AsignadoPor;
IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_UsuarioRol_RevocadoPor')
    ALTER TABLE Seguridad.UsuarioRol DROP CONSTRAINT FK_UsuarioRol_RevocadoPor;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_UsuarioRolFechaAsignacion')
    ALTER TABLE Seguridad.UsuarioRol DROP CONSTRAINT DF_UsuarioRolFechaAsignacion;
GO
IF COL_LENGTH(N'Seguridad.UsuarioRol', N'UsuarioRolAsignadoPor') IS NOT NULL
    ALTER TABLE Seguridad.UsuarioRol DROP COLUMN UsuarioRolAsignadoPor, UsuarioRolFechaAsignacion, UsuarioRolRevocadoPor, UsuarioRolFechaRevocacion;
GO

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_SesionAccesoMotivoCierre')
    ALTER TABLE Seguridad.SesionAcceso DROP CONSTRAINT CK_SesionAccesoMotivoCierre;
IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_SesionAcceso_RevocadaPor')
    ALTER TABLE Seguridad.SesionAcceso DROP CONSTRAINT FK_SesionAcceso_RevocadaPor;
GO
IF COL_LENGTH(N'Seguridad.SesionAcceso', N'SesionAccesoUltimaActividad') IS NOT NULL
    ALTER TABLE Seguridad.SesionAcceso DROP COLUMN SesionAccesoUltimaActividad, SesionAccesoExpiraEn, SesionAccesoMotivoCierre, SesionAccesoRevocadaPor, SesionAccesoAgente;
GO

IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_UsuarioBloqueado')
    ALTER TABLE Seguridad.Usuario DROP CONSTRAINT DF_UsuarioBloqueado;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_UsuarioIntentosFallidos')
    ALTER TABLE Seguridad.Usuario DROP CONSTRAINT DF_UsuarioIntentosFallidos;
IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = N'DF_UsuarioDebeCambiarPassword')
    ALTER TABLE Seguridad.Usuario DROP CONSTRAINT DF_UsuarioDebeCambiarPassword;
GO
IF COL_LENGTH(N'Seguridad.Usuario', N'UsuarioBloqueado') IS NOT NULL
    ALTER TABLE Seguridad.Usuario DROP COLUMN UsuarioBloqueado, UsuarioBloqueadoHasta, UsuarioMotivoBloqueo, UsuarioIntentosFallidos, UsuarioDebeCambiarPassword, UsuarioFechaCambioPassword, UsuarioUltimoAcceso;
GO
