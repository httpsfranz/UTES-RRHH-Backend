/* ================================================================================
   V005__seguridad_notificaciones_y_flujos.sql
   Columnas que piden la revision del tester (2026-10-19) y que el esquema no tenia.

   POR QUE
     Seguridad: una cuenta debe poder BLOQUEARSE (a mano o por intentos fallidos), la sesion debe expirar por
     inactividad y poder revocarse, la asignacion de un rol debe decir quien la hizo y quien la revoco, y la
     auditoria debe guardar el resultado de la operacion y los eventos de seguridad (accesos, denegaciones,
     cambios de permisos) sin que nadie pueda modificarla.
     Notificaciones: tipos con reglas de envio (a que roles y en que ambito), bandeja con fecha de lectura y
     estado de entrega del correo con reintentos.
     Flujos: quien cerro un periodo de asistencia; revision del consolidado (observacion, revisor, fecha); fecha del
     trabajo fuera de jornada que se autoriza ANTES de que exista la asistencia (RIT, Art. 17); expediente PAD que
     nace del acta de una supervision inopinada.

   CAMBIO
     Solo agrega columnas, una tabla (Soporte.TipoNotificacion) y un trigger. No borra ni renombra nada.
   ================================================================================ */

/* ---------- Seguridad.Usuario: bloqueo, intentos y cambio de clave ---------- */
IF COL_LENGTH(N'Seguridad.Usuario', N'UsuarioBloqueado') IS NULL
    ALTER TABLE Seguridad.Usuario ADD
        UsuarioBloqueado           BIT NOT NULL CONSTRAINT DF_UsuarioBloqueado DEFAULT (0),
        UsuarioBloqueadoHasta      DATETIME2(0) NULL,
        UsuarioMotivoBloqueo       NVARCHAR(200) NULL,
        UsuarioIntentosFallidos    INT NOT NULL CONSTRAINT DF_UsuarioIntentosFallidos DEFAULT (0),
        UsuarioDebeCambiarPassword BIT NOT NULL CONSTRAINT DF_UsuarioDebeCambiarPassword DEFAULT (0),
        UsuarioFechaCambioPassword DATETIME2(0) NULL,
        UsuarioUltimoAcceso        DATETIME2(0) NULL;
GO

/* ---------- Seguridad.SesionAcceso: actividad, expiracion y revocacion ---------- */
IF COL_LENGTH(N'Seguridad.SesionAcceso', N'SesionAccesoUltimaActividad') IS NULL
    ALTER TABLE Seguridad.SesionAcceso ADD
        SesionAccesoUltimaActividad DATETIME2(0) NULL,
        SesionAccesoExpiraEn        DATETIME2(0) NULL,
        SesionAccesoMotivoCierre    NVARCHAR(30) NULL,
        SesionAccesoRevocadaPor     INT NULL,
        SesionAccesoAgente          NVARCHAR(300) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_SesionAcceso_RevocadaPor')
    ALTER TABLE Seguridad.SesionAcceso ADD CONSTRAINT FK_SesionAcceso_RevocadaPor
        FOREIGN KEY (SesionAccesoRevocadaPor) REFERENCES Seguridad.Usuario(UsuarioId);
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_SesionAccesoMotivoCierre')
    ALTER TABLE Seguridad.SesionAcceso ADD CONSTRAINT CK_SesionAccesoMotivoCierre CHECK (
        SesionAccesoMotivoCierre IS NULL OR
        SesionAccesoMotivoCierre IN (N'LOGOUT', N'EXPIRADA', N'INACTIVIDAD', N'REVOCADA', N'BLOQUEO'));
GO

/* ---------- Seguridad.UsuarioRol: quien asigno y quien revoco ---------- */
IF COL_LENGTH(N'Seguridad.UsuarioRol', N'UsuarioRolAsignadoPor') IS NULL
    ALTER TABLE Seguridad.UsuarioRol ADD
        UsuarioRolAsignadoPor     INT NULL,
        UsuarioRolFechaAsignacion DATETIME2(0) NULL CONSTRAINT DF_UsuarioRolFechaAsignacion DEFAULT (SYSDATETIME()),
        UsuarioRolRevocadoPor     INT NULL,
        UsuarioRolFechaRevocacion DATETIME2(0) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_UsuarioRol_AsignadoPor')
    ALTER TABLE Seguridad.UsuarioRol ADD CONSTRAINT FK_UsuarioRol_AsignadoPor
        FOREIGN KEY (UsuarioRolAsignadoPor) REFERENCES Seguridad.Usuario(UsuarioId);
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_UsuarioRol_RevocadoPor')
    ALTER TABLE Seguridad.UsuarioRol ADD CONSTRAINT FK_UsuarioRol_RevocadoPor
        FOREIGN KEY (UsuarioRolRevocadoPor) REFERENCES Seguridad.Usuario(UsuarioId);
GO

/* ---------- Seguridad.Auditoria: resultado, modulo y eventos de seguridad ---------- */
IF COL_LENGTH(N'Seguridad.Auditoria', N'AuditoriaResultado') IS NULL
    ALTER TABLE Seguridad.Auditoria ADD
        AuditoriaResultado NVARCHAR(20) NOT NULL CONSTRAINT DF_AuditoriaResultado DEFAULT (N'EXITO'),
        AuditoriaModulo    NVARCHAR(100) NULL,
        AuditoriaDetalle   NVARCHAR(500) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AuditoriaResultado')
    ALTER TABLE Seguridad.Auditoria ADD CONSTRAINT CK_AuditoriaResultado CHECK (
        AuditoriaResultado IN (N'EXITO', N'FALLO', N'DENEGADO'));
GO

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_AuditoriaOperacion')
    ALTER TABLE Seguridad.Auditoria DROP CONSTRAINT CK_AuditoriaOperacion;
GO

ALTER TABLE Seguridad.Auditoria ADD CONSTRAINT CK_AuditoriaOperacion CHECK (
    AuditoriaOperacion IN (N'INSERT', N'UPDATE', N'DELETE', N'LOGIN', N'LOGIN_FALLIDO', N'LOGOUT', N'BLOQUEO',
                           N'DESBLOQUEO', N'ACCESO_DENEGADO', N'PERMISOS', N'REVOCAR_SESION', N'EXPORTAR',
                           N'CONSULTA', N'CAMBIO_PASSWORD'));
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_Auditoria_FechaHora')
    CREATE INDEX IX_Auditoria_FechaHora ON Seguridad.Auditoria (AuditoriaFechaHora DESC) INCLUDE (UsuarioId, AuditoriaTabla, AuditoriaOperacion);
GO

/* La auditoria no se modifica nunca y solo se borra cuando el reinicio de la base de datos de prueba lo declara
   (SESSION_CONTEXT 'purga_auditoria' = 1, ver App\Support\VaciaEsquemasDelSistema). */
CREATE OR ALTER TRIGGER Seguridad.TR_Auditoria_Inmutable
ON Seguridad.Auditoria
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM inserted)
    BEGIN
        THROW 50001, N'Los registros de auditoría no se pueden modificar.', 1;
    END;
    IF CONVERT(INT, SESSION_CONTEXT(N'purga_auditoria')) = 1
    BEGIN
        DELETE a FROM Seguridad.Auditoria a INNER JOIN deleted d ON d.AuditoriaId = a.AuditoriaId;
        RETURN;
    END;
    THROW 50002, N'Los registros de auditoría no se pueden eliminar.', 1;
END;
GO

/* ---------- Soporte.TipoNotificacion: tipos y reglas de envio ---------- */
IF OBJECT_ID(N'Soporte.TipoNotificacion', N'U') IS NULL
CREATE TABLE Soporte.TipoNotificacion (
    TipoNotificacionId           INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoNotificacion PRIMARY KEY,
    TipoNotificacionCodigo       NVARCHAR(50)  NOT NULL,
    TipoNotificacionNombre       NVARCHAR(150) NOT NULL,
    TipoNotificacionDescripcion  NVARCHAR(300) NULL,
    /* Codigos de rol (Seguridad.Rol.RolCodigo) separados por coma que reciben el aviso, ademas del interesado. */
    TipoNotificacionRolesDestino NVARCHAR(300) NULL,
    /* INTERESADO = solo la persona del evento; EESS / MICRORED = los roles de destino con ambito sobre el
       establecimiento del evento; RED = los roles de destino de toda la red. */
    TipoNotificacionAlcance      NVARCHAR(20)  NOT NULL CONSTRAINT DF_TipoNotificacionAlcance DEFAULT (N'EESS'),
    TipoNotificacionAlInteresado BIT NOT NULL CONSTRAINT DF_TipoNotificacionAlInteresado DEFAULT (1),
    TipoNotificacionEnviaCorreo  BIT NOT NULL CONSTRAINT DF_TipoNotificacionEnviaCorreo DEFAULT (0),
    TipoNotificacionEstado       BIT NOT NULL CONSTRAINT DF_TipoNotificacionEstado DEFAULT (1),
    CONSTRAINT UQ_TipoNotificacionCodigo UNIQUE (TipoNotificacionCodigo),
    CONSTRAINT UQ_TipoNotificacionNombre UNIQUE (TipoNotificacionNombre),
    CONSTRAINT CK_TipoNotificacionAlcance CHECK (TipoNotificacionAlcance IN (N'INTERESADO', N'EESS', N'MICRORED', N'RED'))
);
GO

/* ---------- Soporte.Notificacion: lectura y entrega por correo ---------- */
IF COL_LENGTH(N'Soporte.Notificacion', N'NotificacionFechaLectura') IS NULL
    ALTER TABLE Soporte.Notificacion ADD
        NotificacionFechaLectura     DATETIME2(0) NULL,
        NotificacionCorreoEstado     NVARCHAR(20) NOT NULL CONSTRAINT DF_NotificacionCorreoEstado DEFAULT (N'NO_APLICA'),
        NotificacionCorreoIntentos   TINYINT NOT NULL CONSTRAINT DF_NotificacionCorreoIntentos DEFAULT (0),
        NotificacionCorreoFechaEnvio DATETIME2(0) NULL,
        NotificacionCorreoError      NVARCHAR(500) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_NotificacionCorreoEstado')
    ALTER TABLE Soporte.Notificacion ADD CONSTRAINT CK_NotificacionCorreoEstado CHECK (
        NotificacionCorreoEstado IN (N'NO_APLICA', N'PENDIENTE', N'ENVIADO', N'FALLIDO'));
GO

/* ---------- Consolidacion: quien cerro el periodo y revision del consolidado ---------- */
IF COL_LENGTH(N'Consolidacion.PeriodoAsistencia', N'PeriodoAsistenciaCerradoPor') IS NULL
    ALTER TABLE Consolidacion.PeriodoAsistencia ADD PeriodoAsistenciaCerradoPor INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_PeriodoAsistencia_CerradoPor')
    ALTER TABLE Consolidacion.PeriodoAsistencia ADD CONSTRAINT FK_PeriodoAsistencia_CerradoPor
        FOREIGN KEY (PeriodoAsistenciaCerradoPor) REFERENCES Seguridad.Usuario(UsuarioId);
GO

IF COL_LENGTH(N'Consolidacion.ConsolidadoAsistencia', N'ConsolidadoAsistenciaObservacion') IS NULL
    ALTER TABLE Consolidacion.ConsolidadoAsistencia ADD
        ConsolidadoAsistenciaObservacion   NVARCHAR(1000) NULL,
        ConsolidadoAsistenciaRevisadoPor   INT NULL,
        ConsolidadoAsistenciaFechaRevision DATETIME2(0) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_ConsolidadoAsistencia_RevisadoPor')
    ALTER TABLE Consolidacion.ConsolidadoAsistencia ADD CONSTRAINT FK_ConsolidadoAsistencia_RevisadoPor
        FOREIGN KEY (ConsolidadoAsistenciaRevisadoPor) REFERENCES Seguridad.Usuario(UsuarioId);
GO

/* ---------- Compensaciones: fecha del trabajo fuera de jornada autorizado (Art. 17) ---------- */
IF COL_LENGTH(N'Compensaciones.CompensacionHoraria', N'CompensacionHorariaFechaTrabajo') IS NULL
    ALTER TABLE Compensaciones.CompensacionHoraria ADD CompensacionHorariaFechaTrabajo DATE NULL;
GO

/* ---------- Disciplina: expediente que nace del acta de una supervision ---------- */
IF COL_LENGTH(N'Disciplina.ExpedientePad', N'SupervisionInopinadaId') IS NULL
    ALTER TABLE Disciplina.ExpedientePad ADD SupervisionInopinadaId BIGINT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_ExpedientePad_Supervision')
    ALTER TABLE Disciplina.ExpedientePad ADD CONSTRAINT FK_ExpedientePad_Supervision
        FOREIGN KEY (SupervisionInopinadaId) REFERENCES Disciplina.SupervisionInopinada(SupervisionInopinadaId);
GO
