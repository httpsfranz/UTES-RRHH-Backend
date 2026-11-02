/* ================================================================================
   V007__reglas_rit_y_salida_a_produccion.sql
   Lo que el RIT exige y el esquema aun no podia guardar (evaluacion del 2026-11-01).

   POR QUE
     Licencias: tope por caso especial, sexo, regimen y anio, a cuenta de vacaciones (Art. 30 a 53). Aprobacion en dos
     pasos (Art. 16, 30 y 56). Tramites extemporaneos (Art. 64). Descuento de permisos sin goce (Art. 62) e importes
     (Art. 25). Reprogramaciones y plazos (Art. 16). Fotocheck (Art. 13), legajo (Art. 14), entrega de cargo (Art. 56,
     70, 108), desplazamientos (Art. 98), aviso de ausencia (Art. 22), tolerancia extraordinaria (Art. 16 y 27),
     autorizacion de ingreso (Art. 19), facilidad por tuberculosis (Art. 23), contingencia de marcacion (Art. 16),
     medida cautelar (Art. 75) y reconocimientos (Art. 96).
   ================================================================================ */

/* ---- Vinculo laboral: remuneracion (Art. 25) y termino (Art. 129 y 130) ---- */
IF COL_LENGTH(N'Personal.VinculoLaboral', N'VinculoLaboralIngresoMensual') IS NULL
    ALTER TABLE Personal.VinculoLaboral ADD
        VinculoLaboralIngresoMensual DECIMAL(12,2) NULL,
        VinculoLaboralCausaCese      NVARCHAR(40) NULL,
        VinculoLaboralFechaRenuncia  DATE NULL;
GO

/* ---- Tipos de licencia ---- */
IF COL_LENGTH(N'Solicitudes.TipoLicencia', N'TipoLicenciaSexo') IS NULL
    ALTER TABLE Solicitudes.TipoLicencia ADD
        TipoLicenciaSexo              CHAR(1) NULL,
        TipoLicenciaMaximoDiasCas     INT NULL,
        TipoLicenciaMaximoDiasAnio    INT NULL,
        TipoLicenciaACuentaVacaciones BIT NOT NULL CONSTRAINT DF_TipoLicenciaACuentaVacaciones DEFAULT (0),
        TipoLicenciaExigeAnticipacion BIT NOT NULL CONSTRAINT DF_TipoLicenciaExigeAnticipacion DEFAULT (1);
GO

/* ---- Solicitudes: dos pasos y tramites extemporaneos ---- */
IF COL_LENGTH(N'Solicitudes.Licencia', N'LicenciaVistoBuenoPor') IS NULL
    ALTER TABLE Solicitudes.Licencia ADD
        LicenciaCasoEspecial    NVARCHAR(30) NULL,
        LicenciaVistoBuenoPor   INT NULL CONSTRAINT FK_Licencia_VistoBueno REFERENCES Seguridad.Usuario(UsuarioId),
        LicenciaFechaVistoBueno DATETIME2(0) NULL,
        LicenciaEsExtemporanea  BIT NOT NULL CONSTRAINT DF_LicenciaEsExtemporanea DEFAULT (0);
GO
IF COL_LENGTH(N'Solicitudes.Papeleta', N'PapeletaVistoBuenoPor') IS NULL
    ALTER TABLE Solicitudes.Papeleta ADD
        PapeletaVistoBuenoPor       INT NULL CONSTRAINT FK_Papeleta_VistoBueno REFERENCES Seguridad.Usuario(UsuarioId),
        PapeletaFechaVistoBueno     DATETIME2(0) NULL,
        PapeletaEsUrgente           BIT NOT NULL CONSTRAINT DF_PapeletaEsUrgente DEFAULT (0),
        PapeletaPartoMultiple       BIT NOT NULL CONSTRAINT DF_PapeletaPartoMultiple DEFAULT (0),
        PapeletaFechaNacimientoHijo DATE NULL,
        PapeletaUnidadDestino       NVARCHAR(200) NULL,
        PapeletaConformeDestino     NVARCHAR(150) NULL;
GO
IF COL_LENGTH(N'Solicitudes.DescansoMedico', N'DescansoMedicoEsExtemporaneo') IS NULL
    ALTER TABLE Solicitudes.DescansoMedico ADD DescansoMedicoEsExtemporaneo BIT NOT NULL CONSTRAINT DF_DescansoMedicoEsExtemporaneo DEFAULT (0);
GO
IF COL_LENGTH(N'Asistencia.JustificacionFalta', N'JustificacionFaltaEsExtemporanea') IS NULL
    ALTER TABLE Asistencia.JustificacionFalta ADD JustificacionFaltaEsExtemporanea BIT NOT NULL CONSTRAINT DF_JustificacionFaltaEsExtemporanea DEFAULT (0);
GO

/* ---- Asistencia y consolidado: permisos sin goce (Art. 62) ---- */
IF COL_LENGTH(N'Asistencia.AsistenciaDiaria', N'AsistenciaDiariaMinutosSinGoce') IS NULL
    ALTER TABLE Asistencia.AsistenciaDiaria ADD AsistenciaDiariaMinutosSinGoce INT NOT NULL CONSTRAINT DF_AsistenciaDiariaMinutosSinGoce DEFAULT (0);
GO
IF COL_LENGTH(N'Consolidacion.ConsolidadoAsistencia', N'ConsolidadoAsistenciaMinutosSinGoce') IS NULL
    ALTER TABLE Consolidacion.ConsolidadoAsistencia ADD ConsolidadoAsistenciaMinutosSinGoce INT NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaMinutosSinGoce DEFAULT (0);
GO

/* ---- Programacion: hasta 2 reprogramaciones (Art. 16) ---- */
IF COL_LENGTH(N'Programacion.ProgramacionPeriodo', N'ProgramacionPeriodoReprogramaciones') IS NULL
    ALTER TABLE Programacion.ProgramacionPeriodo ADD
        ProgramacionPeriodoReprogramaciones INT NOT NULL CONSTRAINT DF_ProgramacionPeriodoReprogramaciones DEFAULT (0),
        ProgramacionPeriodoMotivoTardio     NVARCHAR(500) NULL;
GO

/* ---- Vacaciones y disciplina ---- */
IF COL_LENGTH(N'Vacaciones.GoceVacacional', N'GoceVacacionalEsAdelanto') IS NULL
    ALTER TABLE Vacaciones.GoceVacacional ADD
        GoceVacacionalEsAdelanto    BIT NOT NULL CONSTRAINT DF_GoceVacacionalEsAdelanto DEFAULT (0),
        GoceVacacionalFechaSolicitud DATETIME2(0) NOT NULL CONSTRAINT DF_GoceVacacionalFechaSolicitud DEFAULT (SYSDATETIME());
GO
IF COL_LENGTH(N'Disciplina.ExpedientePad', N'ExpedientePadMedidaCautelar') IS NULL
    ALTER TABLE Disciplina.ExpedientePad ADD ExpedientePadMedidaCautelar BIT NOT NULL CONSTRAINT DF_ExpedientePadMedidaCautelar DEFAULT (0);
GO

/* ---- Fotocheck (Art. 13) ---- */
IF OBJECT_ID(N'Personal.Fotocheck', N'U') IS NULL
CREATE TABLE Personal.Fotocheck (
    FotocheckId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Fotocheck PRIMARY KEY,
    TrabajadorId           INT NOT NULL CONSTRAINT FK_Fotocheck_Trabajador REFERENCES Personal.Trabajador(TrabajadorId),
    DocumentoSustentoId    BIGINT NULL CONSTRAINT FK_Fotocheck_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    FotocheckNumero        NVARCHAR(30) NOT NULL,
    FotocheckFechaEntrega  DATE NOT NULL,
    FotocheckFechaDenuncia DATE NULL,
    FotocheckFechaDevolucion DATE NULL,
    FotocheckEsDuplicado   BIT NOT NULL CONSTRAINT DF_FotocheckEsDuplicado DEFAULT (0),
    FotocheckObservacion   NVARCHAR(500) NULL,
    FotocheckEstado        NVARCHAR(20) NOT NULL CONSTRAINT DF_FotocheckEstado DEFAULT (N'VIGENTE'),
    CONSTRAINT UQ_FotocheckNumero UNIQUE (FotocheckNumero),
    CONSTRAINT CK_FotocheckEstado CHECK (FotocheckEstado IN (N'VIGENTE', N'PERDIDO', N'DEVUELTO', N'ANULADO'))
);
GO

/* ---- Legajo (Art. 11 y 14) ---- */
IF OBJECT_ID(N'Personal.LegajoDocumento', N'U') IS NULL
CREATE TABLE Personal.LegajoDocumento (
    LegajoDocumentoId     BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_LegajoDocumento PRIMARY KEY,
    TrabajadorId          INT NOT NULL CONSTRAINT FK_LegajoDocumento_Trabajador REFERENCES Personal.Trabajador(TrabajadorId),
    DocumentoSustentoId   BIGINT NULL CONSTRAINT FK_LegajoDocumento_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    LegajoDocumentoTipo   NVARCHAR(40) NOT NULL,
    LegajoDocumentoNombre NVARCHAR(200) NOT NULL,
    LegajoDocumentoFecha  DATE NOT NULL,
    LegajoDocumentoObservacion NVARCHAR(500) NULL,
    LegajoDocumentoEstado BIT NOT NULL CONSTRAINT DF_LegajoDocumentoEstado DEFAULT (1)
);
GO

/* ---- Entrega de cargo (Art. 56, 70 g, 84.20, 108 y 109) ---- */
IF OBJECT_ID(N'Personal.EntregaCargo', N'U') IS NULL
CREATE TABLE Personal.EntregaCargo (
    EntregaCargoId       BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_EntregaCargo PRIMARY KEY,
    VinculoLaboralId     INT NOT NULL CONSTRAINT FK_EntregaCargo_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    VinculoLaboralRecibeId INT NULL CONSTRAINT FK_EntregaCargo_Recibe REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    DocumentoSustentoId  BIGINT NULL CONSTRAINT FK_EntregaCargo_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    EntregaCargoMotivo   NVARCHAR(20) NOT NULL,
    EntregaCargoFecha    DATE NOT NULL,
    EntregaCargoDetalle  NVARCHAR(1000) NULL,
    EntregaCargoEstado   NVARCHAR(20) NOT NULL CONSTRAINT DF_EntregaCargoEstado DEFAULT (N'CONFORME'),
    CONSTRAINT CK_EntregaCargoMotivo CHECK (EntregaCargoMotivo IN (N'VACACIONES', N'LICENCIA', N'DESPLAZAMIENTO', N'CESE')),
    CONSTRAINT CK_EntregaCargoEstado CHECK (EntregaCargoEstado IN (N'CONFORME', N'OBSERVADO', N'ANULADO'))
);
GO

/* ---- Desplazamientos temporales (Art. 98 y 99) ---- */
IF OBJECT_ID(N'Personal.DesplazamientoTemporal', N'U') IS NULL
CREATE TABLE Personal.DesplazamientoTemporal (
    DesplazamientoTemporalId BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DesplazamientoTemporal PRIMARY KEY,
    VinculoLaboralId     INT NOT NULL CONSTRAINT FK_Desplazamiento_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    EessDestinoId        INT NOT NULL CONSTRAINT FK_Desplazamiento_Eess REFERENCES Organizacion.EstablecimientoSalud(EessId),
    DocumentoSustentoId  BIGINT NULL CONSTRAINT FK_Desplazamiento_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    DesplazamientoTemporalTipo NVARCHAR(30) NOT NULL,
    DesplazamientoTemporalFechaInicio DATE NOT NULL,
    DesplazamientoTemporalFechaFin    DATE NOT NULL,
    DesplazamientoTemporalMotivo      NVARCHAR(500) NULL,
    DesplazamientoTemporalEstado      NVARCHAR(20) NOT NULL CONSTRAINT DF_DesplazamientoTemporalEstado DEFAULT (N'VIGENTE'),
    CONSTRAINT CK_DesplazamientoFechas CHECK (DesplazamientoTemporalFechaFin >= DesplazamientoTemporalFechaInicio),
    CONSTRAINT CK_DesplazamientoEstado CHECK (DesplazamientoTemporalEstado IN (N'VIGENTE', N'CONCLUIDO', N'ANULADO'))
);
GO

/* ---- Aviso de ausencia (Art. 22 y 84.34, Anexo 009) ---- */
IF OBJECT_ID(N'Asistencia.AvisoAusencia', N'U') IS NULL
CREATE TABLE Asistencia.AvisoAusencia (
    AvisoAusenciaId       BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AvisoAusencia PRIMARY KEY,
    VinculoLaboralId      INT NOT NULL CONSTRAINT FK_AvisoAusencia_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    UsuarioId             INT NULL CONSTRAINT FK_AvisoAusencia_Usuario REFERENCES Seguridad.Usuario(UsuarioId),
    AvisoAusenciaFecha    DATE NOT NULL,
    AvisoAusenciaFechaHora DATETIME2(0) NOT NULL CONSTRAINT DF_AvisoAusenciaFechaHora DEFAULT (SYSDATETIME()),
    AvisoAusenciaMedio    NVARCHAR(30) NOT NULL,
    AvisoAusenciaMotivo   NVARCHAR(500) NOT NULL,
    AvisoAusenciaDentroDePlazo BIT NOT NULL CONSTRAINT DF_AvisoAusenciaDentroDePlazo DEFAULT (1),
    AvisoAusenciaEstado   NVARCHAR(20) NOT NULL CONSTRAINT DF_AvisoAusenciaEstado DEFAULT (N'REGISTRADO'),
    CONSTRAINT CK_AvisoAusenciaEstado CHECK (AvisoAusenciaEstado IN (N'REGISTRADO', N'ANULADO'))
);
GO

/* ---- Tolerancia extraordinaria (Art. 16 y 27) ---- */
IF OBJECT_ID(N'Asistencia.ToleranciaExtraordinaria', N'U') IS NULL
CREATE TABLE Asistencia.ToleranciaExtraordinaria (
    ToleranciaExtraordinariaId BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ToleranciaExtraordinaria PRIMARY KEY,
    EessId                INT NOT NULL CONSTRAINT FK_ToleranciaExtra_Eess REFERENCES Organizacion.EstablecimientoSalud(EessId),
    UsuarioId             INT NULL CONSTRAINT FK_ToleranciaExtra_Usuario REFERENCES Seguridad.Usuario(UsuarioId),
    DocumentoSustentoId   BIGINT NULL CONSTRAINT FK_ToleranciaExtra_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    ToleranciaExtraordinariaFechaInicio DATE NOT NULL,
    ToleranciaExtraordinariaFechaFin    DATE NOT NULL,
    ToleranciaExtraordinariaMinutos     INT NOT NULL,
    ToleranciaExtraordinariaMotivo      NVARCHAR(500) NOT NULL,
    ToleranciaExtraordinariaEstado      BIT NOT NULL CONSTRAINT DF_ToleranciaExtraordinariaEstado DEFAULT (1),
    CONSTRAINT CK_ToleranciaExtraFechas CHECK (ToleranciaExtraordinariaFechaFin >= ToleranciaExtraordinariaFechaInicio),
    CONSTRAINT CK_ToleranciaExtraMinutos CHECK (ToleranciaExtraordinariaMinutos BETWEEN 1 AND 120)
);
GO

/* ---- Autorizacion de ingreso fuera de horario o en dia no laborable (Art. 19 y Disp. Compl. 11a) ---- */
IF OBJECT_ID(N'Asistencia.AutorizacionIngreso', N'U') IS NULL
CREATE TABLE Asistencia.AutorizacionIngreso (
    AutorizacionIngresoId BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AutorizacionIngreso PRIMARY KEY,
    VinculoLaboralId      INT NOT NULL CONSTRAINT FK_AutorizacionIngreso_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    UsuarioId             INT NULL CONSTRAINT FK_AutorizacionIngreso_Usuario REFERENCES Seguridad.Usuario(UsuarioId),
    AutorizacionIngresoFecha DATE NOT NULL,
    AutorizacionIngresoFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_AutorizacionIngresoFechaRegistro DEFAULT (SYSDATETIME()),
    AutorizacionIngresoMotivo NVARCHAR(500) NOT NULL,
    AutorizacionIngresoEstado BIT NOT NULL CONSTRAINT DF_AutorizacionIngresoEstado DEFAULT (1)
);
GO

/* ---- Facilidad horaria por tratamiento de tuberculosis (Art. 23 d, Ley 30287) ---- */
IF OBJECT_ID(N'Asistencia.FacilidadHoraria', N'U') IS NULL
CREATE TABLE Asistencia.FacilidadHoraria (
    FacilidadHorariaId   BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_FacilidadHoraria PRIMARY KEY,
    VinculoLaboralId     INT NOT NULL CONSTRAINT FK_FacilidadHoraria_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    DocumentoSustentoId  BIGINT NULL CONSTRAINT FK_FacilidadHoraria_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    FacilidadHorariaMomento NVARCHAR(10) NOT NULL,
    FacilidadHorariaMinutos INT NOT NULL CONSTRAINT DF_FacilidadHorariaMinutos DEFAULT (60),
    FacilidadHorariaFechaInicio DATE NOT NULL,
    FacilidadHorariaFechaFin    DATE NOT NULL,
    FacilidadHorariaObservacion NVARCHAR(500) NULL,
    FacilidadHorariaEstado BIT NOT NULL CONSTRAINT DF_FacilidadHorariaEstado DEFAULT (1),
    CONSTRAINT CK_FacilidadHorariaMomento CHECK (FacilidadHorariaMomento IN (N'INGRESO', N'SALIDA')),
    CONSTRAINT CK_FacilidadHorariaMinutos CHECK (FacilidadHorariaMinutos BETWEEN 1 AND 60),
    CONSTRAINT CK_FacilidadHorariaFechas CHECK (FacilidadHorariaFechaFin >= FacilidadHorariaFechaInicio)
);
GO

/* ---- Contingencia: marcacion manual por fuerza mayor en todo un EESS (Art. 16) ---- */
IF OBJECT_ID(N'Biometria.ContingenciaMarcacion', N'U') IS NULL
CREATE TABLE Biometria.ContingenciaMarcacion (
    ContingenciaMarcacionId BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ContingenciaMarcacion PRIMARY KEY,
    EessId               INT NOT NULL CONSTRAINT FK_Contingencia_Eess REFERENCES Organizacion.EstablecimientoSalud(EessId),
    UsuarioId            INT NULL CONSTRAINT FK_Contingencia_Usuario REFERENCES Seguridad.Usuario(UsuarioId),
    DocumentoSustentoId  BIGINT NULL CONSTRAINT FK_Contingencia_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    ContingenciaMarcacionFechaInicio DATE NOT NULL,
    ContingenciaMarcacionFechaFin    DATE NOT NULL,
    ContingenciaMarcacionMotivo      NVARCHAR(500) NOT NULL,
    ContingenciaMarcacionEstado      BIT NOT NULL CONSTRAINT DF_ContingenciaMarcacionEstado DEFAULT (1),
    CONSTRAINT CK_ContingenciaFechas CHECK (ContingenciaMarcacionFechaFin >= ContingenciaMarcacionFechaInicio)
);
GO

/* ---- Reconocimientos (Art. 96 y 97) ---- */
IF OBJECT_ID(N'Disciplina.ReconocimientoServidor', N'U') IS NULL
CREATE TABLE Disciplina.ReconocimientoServidor (
    ReconocimientoServidorId BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ReconocimientoServidor PRIMARY KEY,
    VinculoLaboralId     INT NOT NULL CONSTRAINT FK_Reconocimiento_Vinculo REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    DocumentoSustentoId  BIGINT NULL CONSTRAINT FK_Reconocimiento_Documento REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    ReconocimientoServidorAnio   INT NOT NULL,
    ReconocimientoServidorTipo   NVARCHAR(20) NOT NULL,
    ReconocimientoServidorMotivo NVARCHAR(500) NOT NULL,
    ReconocimientoServidorResolucion NVARCHAR(60) NULL,
    ReconocimientoServidorFecha  DATE NOT NULL,
    ReconocimientoServidorEstado BIT NOT NULL CONSTRAINT DF_ReconocimientoServidorEstado DEFAULT (1),
    CONSTRAINT CK_ReconocimientoTipo CHECK (ReconocimientoServidorTipo IN (N'AGRADECIMIENTO', N'FELICITACION', N'DIPLOMA'))
);
GO
