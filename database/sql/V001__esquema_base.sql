/* ================================================================================
   V001__esquema_base.sql
   SISTEMA DE CONTROL DE ASISTENCIA Y GESTION DE PERSONAL
   RED DE SALUD TRUJILLO  -  Microredes y Establecimientos de Salud (EESS)

   MOTOR      : Microsoft SQL Server 2016+
   VERSION    : 3.0  (auditoria, reestructuracion integral y modelo organizacional
                de dos niveles: Microred -> Establecimiento de Salud)

   --------------------------------------------------------------------------------
   *** ARCHIVO INMUTABLE ***
   Este script YA FUE APLICADO en las maquinas del equipo y en el ambiente de
   integracion. NO SE EDITA NUNCA, por ningun motivo.
   Todo cambio posterior al esquema se hace en un archivo NUEVO:
       V002__<descripcion>.sql, V003__<descripcion>.sql, ...
   El trait App\Support\RunsSqlFile calcula un SHA-256 de este archivo y aborta
   la migracion si detecta que fue modificado despues de aplicarse.
   --------------------------------------------------------------------------------

   DIFERENCIAS RESPECTO DEL SCRIPT ORIGINAL (DB_ControlAsistencia.sql)
     - Se retiro  CREATE DATABASE / USE [DB_ControlAsistencia]
       (la base la crea el desarrollador una sola vez; Laravel se conecta a ella
        ya existente y 'USE' no puede ejecutarse desde PDO).
     - Se retiro  la seccion 22 VALIDACION FINAL
       (eran SELECT de diagnostico; devolver resultsets dentro de PDO::exec deja
        cursores pendientes y rompe la siguiente consulta.
        Se movieron a database/diagnostico/validacion_esquema.sql, para SSMS).
     - Se retiro  la nota final de migracion desde v1
       (se movio a docs/NOTA_MIGRACION_DESDE_V1.md).
     - El contenido DDL/DML no fue alterado en ninguna linea.

   MODELO ORGANIZACIONAL
     NO existe tabla "Red": la Red de Salud Trujillo es la entidad completa.
     NO existen autorreferencias (ninguna tabla se apunta a si misma).
     La jerarquia es una sola clave foranea directa:
         Organizacion.EstablecimientoSalud.MicroredId -> Organizacion.Microred

   ORDEN DE CREACION
     01. Esquemas
     02. Soporte  - base documental (referenciada por casi todo)
     03. Organizacion - Microred y Establecimientos de Salud
     04. Configuracion - jornadas, tolerancias, turnos, horarios
     05. Personal - trabajador, colegiatura, vinculo laboral, horario asignado
     06. Organizacion (dependiente) - responsables de cada EESS
     07. Seguridad          08. Biometria         09. Soporte (dependiente)
     10. Programacion       11. Asistencia        12. Solicitudes
     13. Vacaciones         14. Compensaciones    15. Consolidacion
     16. Liquidacion        17. Disciplina        18. Auditoria
     19. Indices            20. Catalogos (datos semilla)
     21. Vistas de apoyo

   CONVENCIONES
     - PK            : <Tabla>Id
     - Columnas      : <Tabla><Atributo>
     - Restricciones : PK_ / FK_ / UQ_ / UX_ / CK_ / DF_ / IX_
     - Estados de flujo : PENDIENTE | APROBADO | RECHAZADO | ANULADO
     - Estados de programacion : BORRADOR | PUBLICADA | CERRADA | ANULADA
     - Los codigos NULL usan INDICE UNICO FILTRADO, nunca UNIQUE
       (SQL Server solo admite un NULL por restriccion UNIQUE)

   IDEMPOTENCIA
     Cada objeto se crea solo si no existe. Correr este script dos veces no
     produce error, pero TAMPOCO aplica cambios a objetos ya creados: por eso
     un cambio de esquema JAMAS debe hacerse editando este archivo.
   ================================================================================ */

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

/* ================================================================================
   01. ESQUEMAS
   ================================================================================ */
IF SCHEMA_ID(N'Organizacion')   IS NULL EXEC(N'CREATE SCHEMA Organizacion');
IF SCHEMA_ID(N'Personal')       IS NULL EXEC(N'CREATE SCHEMA Personal');
IF SCHEMA_ID(N'Seguridad')      IS NULL EXEC(N'CREATE SCHEMA Seguridad');
IF SCHEMA_ID(N'Biometria')      IS NULL EXEC(N'CREATE SCHEMA Biometria');
IF SCHEMA_ID(N'Configuracion')  IS NULL EXEC(N'CREATE SCHEMA Configuracion');
IF SCHEMA_ID(N'Programacion')   IS NULL EXEC(N'CREATE SCHEMA Programacion');
IF SCHEMA_ID(N'Asistencia')     IS NULL EXEC(N'CREATE SCHEMA Asistencia');
IF SCHEMA_ID(N'Solicitudes')    IS NULL EXEC(N'CREATE SCHEMA Solicitudes');
IF SCHEMA_ID(N'Soporte')        IS NULL EXEC(N'CREATE SCHEMA Soporte');
IF SCHEMA_ID(N'Vacaciones')     IS NULL EXEC(N'CREATE SCHEMA Vacaciones');
IF SCHEMA_ID(N'Compensaciones') IS NULL EXEC(N'CREATE SCHEMA Compensaciones');
IF SCHEMA_ID(N'Consolidacion')  IS NULL EXEC(N'CREATE SCHEMA Consolidacion');
IF SCHEMA_ID(N'Disciplina')     IS NULL EXEC(N'CREATE SCHEMA Disciplina');
GO

/* ================================================================================
   02. SOPORTE - BASE DOCUMENTAL
   --------------------------------------------------------------------------------
   DocumentoSustento es la UNICA entidad documental del sistema.
   El archivo NO se almacena en la base de datos: se guarda la RUTA / URL / clave
   del almacenamiento externo (S3, disco, MinIO). Sin VARBINARY.
   La usan: papeletas, licencias, justificaciones, colegiaturas, PAD,
   designaciones de responsable y la carga de programacion.
   ================================================================================ */
IF OBJECT_ID(N'Soporte.DocumentoSustento', N'U') IS NULL
CREATE TABLE Soporte.DocumentoSustento (
    DocumentoSustentoId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DocumentoSustento PRIMARY KEY,
    DocumentoSustentoNombre        NVARCHAR(255) NOT NULL,
    DocumentoSustentoRuta          NVARCHAR(500) NULL,   -- ruta/URL/storage key externo
    DocumentoSustentoTipo          NVARCHAR(100) NULL,   -- MIME o clasificacion funcional
    DocumentoSustentoExtension     NVARCHAR(10)  NULL,
    DocumentoSustentoTamanoBytes   BIGINT        NULL,
    DocumentoSustentoHash          NVARCHAR(128) NULL,   -- SHA-256 para deteccion de duplicados
    DocumentoSustentoFechaRegistro DATETIME2(0)  NOT NULL CONSTRAINT DF_DocumentoSustentoFechaRegistro DEFAULT (SYSDATETIME()),
    CONSTRAINT CK_DocumentoSustentoTamano CHECK (DocumentoSustentoTamanoBytes IS NULL OR DocumentoSustentoTamanoBytes >= 0)
);
GO

/* ================================================================================
   03. ORGANIZACION - MICRORED Y ESTABLECIMIENTOS DE SALUD
   --------------------------------------------------------------------------------
   ESTRUCTURA REAL DE LA RED DE SALUD TRUJILLO: DOS NIVELES, NO MAS.

       MICRORED (equivale al distrito)
          +-- ESTABLECIMIENTO DE SALUD (EESS)
          +-- ESTABLECIMIENTO DE SALUD (EESS)
          +-- ...

   Ejemplo:
       Microred La Esperanza
          +-- C.S. La Esperanza
          +-- P.S. Wichanzao
          +-- ... (8 establecimientos en total)

   NO existe una tabla "Red": la Red de Salud Trujillo es la entidad completa, es
   decir, esta propia base de datos. No es un dato que haya que almacenar en filas.

   NO se usa autorreferencia (una tabla apuntandose a si misma). Con exactamente
   dos niveles fijos, una simple clave foranea EESS -> Microred es suficiente,
   mas clara y mas facil de consultar.
   ================================================================================ */

/* Clasifica QUE ES cada establecimiento: centro de salud, puesto de salud,
   hospital o una unidad administrativa de la sede. */
IF OBJECT_ID(N'Organizacion.TipoEstablecimiento', N'U') IS NULL
CREATE TABLE Organizacion.TipoEstablecimiento (
    TipoEstablecimientoId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoEstablecimiento PRIMARY KEY,
    TipoEstablecimientoCodigo      NVARCHAR(30)  NOT NULL,
    TipoEstablecimientoNombre      NVARCHAR(100) NOT NULL,
    TipoEstablecimientoDescripcion NVARCHAR(250) NULL,
    TipoEstablecimientoEstado      BIT NOT NULL CONSTRAINT DF_TipoEstablecimientoEstado DEFAULT (1),
    CONSTRAINT UQ_TipoEstablecimientoCodigo UNIQUE (TipoEstablecimientoCodigo),
    CONSTRAINT UQ_TipoEstablecimientoNombre UNIQUE (TipoEstablecimientoNombre)
);
GO

/* MICRORED = distrito. Es el nivel superior del modelo. */
IF OBJECT_ID(N'Organizacion.Microred', N'U') IS NULL
CREATE TABLE Organizacion.Microred (
    MicroredId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Microred PRIMARY KEY,
    MicroredCodigo      NVARCHAR(30)  NOT NULL,
    MicroredNombre      NVARCHAR(150) NOT NULL,
    MicroredDistrito    NVARCHAR(100) NULL,
    MicroredUbigeo      NVARCHAR(10)  NULL,
    MicroredDireccion   NVARCHAR(300) NULL,
    MicroredTelefono    NVARCHAR(30)  NULL,
    MicroredDescripcion NVARCHAR(300) NULL,
    MicroredEstado      BIT NOT NULL CONSTRAINT DF_MicroredEstado DEFAULT (1),
    CONSTRAINT UQ_MicroredCodigo UNIQUE (MicroredCodigo),
    CONSTRAINT UQ_MicroredNombre UNIQUE (MicroredNombre)
);
GO

/* ESTABLECIMIENTO DE SALUD. Cada EESS pertenece a UNA Microred.
   Esa es toda la jerarquia: una clave foranea directa y explicita. */
IF OBJECT_ID(N'Organizacion.EstablecimientoSalud', N'U') IS NULL
CREATE TABLE Organizacion.EstablecimientoSalud (
    EessId                INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_EstablecimientoSalud PRIMARY KEY,
    MicroredId            INT NOT NULL,            -- <-- a que Microred (distrito) pertenece
    TipoEstablecimientoId INT NOT NULL,
    EessCodigo            NVARCHAR(30)  NOT NULL,
    EessCodigoRenipres    NVARCHAR(20)  NULL,      -- codigo RENIPRESS oficial
    EessNombre            NVARCHAR(150) NOT NULL,
    EessCategoria         NVARCHAR(20)  NULL,      -- I-1, I-2, I-3, I-4, II-1 ...
    EessUbigeo            NVARCHAR(10)  NULL,
    EessDireccion         NVARCHAR(300) NULL,
    EessTelefono          NVARCHAR(30)  NULL,
    EessDescripcion       NVARCHAR(300) NULL,
    EessEstado            BIT NOT NULL CONSTRAINT DF_EessEstado DEFAULT (1),
    CONSTRAINT FK_EstablecimientoSalud_Microred FOREIGN KEY (MicroredId)
        REFERENCES Organizacion.Microred(MicroredId),
    CONSTRAINT FK_EstablecimientoSalud_TipoEstablecimiento FOREIGN KEY (TipoEstablecimientoId)
        REFERENCES Organizacion.TipoEstablecimiento(TipoEstablecimientoId),
    CONSTRAINT UQ_EessCodigo UNIQUE (EessCodigo),
    CONSTRAINT UQ_EessNombreMicrored UNIQUE (MicroredId, EessNombre)
);
GO

IF OBJECT_ID(N'Organizacion.TipoResponsabilidad', N'U') IS NULL
CREATE TABLE Organizacion.TipoResponsabilidad (
    TipoResponsabilidadId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoResponsabilidad PRIMARY KEY,
    TipoResponsabilidadCodigo      NVARCHAR(30)  NOT NULL,
    TipoResponsabilidadNombre      NVARCHAR(150) NOT NULL,
    TipoResponsabilidadDescripcion NVARCHAR(300) NULL,
    TipoResponsabilidadEstado      BIT NOT NULL CONSTRAINT DF_TipoResponsabilidadEstado DEFAULT (1),
    CONSTRAINT UQ_TipoResponsabilidadCodigo UNIQUE (TipoResponsabilidadCodigo),
    CONSTRAINT UQ_TipoResponsabilidadNombre UNIQUE (TipoResponsabilidadNombre)
);
GO

/* ================================================================================
   04. CONFIGURACION - JORNADAS, TOLERANCIAS, TURNOS Y HORARIOS
   ================================================================================ */
IF OBJECT_ID(N'Configuracion.TipoJornada', N'U') IS NULL
CREATE TABLE Configuracion.TipoJornada (
    TipoJornadaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoJornada PRIMARY KEY,
    TipoJornadaCodigo      NVARCHAR(30)  NOT NULL,
    TipoJornadaNombre      NVARCHAR(100) NOT NULL,
    TipoJornadaDescripcion NVARCHAR(250) NULL,
    TipoJornadaEstado      BIT NOT NULL CONSTRAINT DF_TipoJornadaEstado DEFAULT (1),
    CONSTRAINT UQ_TipoJornadaCodigo UNIQUE (TipoJornadaCodigo),
    CONSTRAINT UQ_TipoJornadaNombre UNIQUE (TipoJornadaNombre)
);
GO

/* Parametro con VIGENCIA: si cambia la jornada legal, se agrega una fila nueva
   en lugar de sobrescribir la historica. */
IF OBJECT_ID(N'Configuracion.ParametroJornada', N'U') IS NULL
CREATE TABLE Configuracion.ParametroJornada (
    ParametroJornadaId             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ParametroJornada PRIMARY KEY,
    TipoJornadaId                  INT NOT NULL,
    ParametroJornadaVigenciaDesde  DATE NOT NULL CONSTRAINT DF_ParametroJornadaVigenciaDesde DEFAULT (CONVERT(date, SYSDATETIME())),
    ParametroJornadaVigenciaHasta  DATE NULL,
    ParametroJornadaHorasDiarias   DECIMAL(5,2) NOT NULL,
    ParametroJornadaHorasSemanales DECIMAL(6,2) NULL,
    ParametroJornadaHorasMensuales DECIMAL(7,2) NULL,
    ParametroJornadaEstado         BIT NOT NULL CONSTRAINT DF_ParametroJornadaEstado DEFAULT (1),
    CONSTRAINT FK_ParametroJornada_TipoJornada FOREIGN KEY (TipoJornadaId)
        REFERENCES Configuracion.TipoJornada(TipoJornadaId),
    CONSTRAINT UQ_ParametroJornadaVigencia UNIQUE (TipoJornadaId, ParametroJornadaVigenciaDesde),
    CONSTRAINT CK_ParametroJornadaHoras CHECK (ParametroJornadaHorasDiarias >= 0),
    CONSTRAINT CK_ParametroJornadaFechas CHECK (
        ParametroJornadaVigenciaHasta IS NULL OR
        ParametroJornadaVigenciaHasta >= ParametroJornadaVigenciaDesde)
);
GO

/* TablaTolerancia / TramoTolerancia = ESCALA DE CLASIFICACION Y DESCUENTO por
   tardanza (segun el Reglamento Interno de Trabajo). NO es el minuto de gracia:
   ese vive en Turno.TurnoToleranciaEntradaMinutos. Los dos conceptos quedan
   ahora explicitamente separados. */
IF OBJECT_ID(N'Configuracion.TablaTolerancia', N'U') IS NULL
CREATE TABLE Configuracion.TablaTolerancia (
    TablaToleranciaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TablaTolerancia PRIMARY KEY,
    TablaToleranciaCodigo      NVARCHAR(30)  NOT NULL,
    TablaToleranciaNombre      NVARCHAR(100) NOT NULL,
    TablaToleranciaDescripcion NVARCHAR(250) NULL,
    TablaToleranciaEstado      BIT NOT NULL CONSTRAINT DF_TablaToleranciaEstado DEFAULT (1),
    CONSTRAINT UQ_TablaToleranciaCodigo UNIQUE (TablaToleranciaCodigo),
    CONSTRAINT UQ_TablaToleranciaNombre UNIQUE (TablaToleranciaNombre)
);
GO

IF OBJECT_ID(N'Configuracion.TramoTolerancia', N'U') IS NULL
CREATE TABLE Configuracion.TramoTolerancia (
    TramoToleranciaId             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TramoTolerancia PRIMARY KEY,
    TablaToleranciaId             INT NOT NULL,
    TramoToleranciaTipo           NVARCHAR(30) NOT NULL,   -- TARDANZA | SALIDA_ANTICIPADA
    TramoToleranciaMinutosDesde   INT NOT NULL,
    TramoToleranciaMinutosHasta   INT NULL,                -- NULL = sin limite superior
    TramoToleranciaFactorDescuento DECIMAL(5,2) NOT NULL CONSTRAINT DF_TramoToleranciaFactor DEFAULT (0),
    TramoToleranciaDescripcion    NVARCHAR(250) NULL,
    CONSTRAINT FK_TramoTolerancia_TablaTolerancia FOREIGN KEY (TablaToleranciaId)
        REFERENCES Configuracion.TablaTolerancia(TablaToleranciaId),
    CONSTRAINT UQ_TramoTolerancia UNIQUE (TablaToleranciaId, TramoToleranciaTipo, TramoToleranciaMinutosDesde),
    CONSTRAINT CK_TramoToleranciaTipo CHECK (TramoToleranciaTipo IN (N'TARDANZA', N'SALIDA_ANTICIPADA')),
    CONSTRAINT CK_TramoToleranciaMinutos CHECK (
        TramoToleranciaMinutosDesde >= 0 AND
        (TramoToleranciaMinutosHasta IS NULL OR TramoToleranciaMinutosHasta >= TramoToleranciaMinutosDesde)),
    CONSTRAINT CK_TramoToleranciaFactor CHECK (TramoToleranciaFactorDescuento >= 0)
);
GO

/* TURNO = bloque horario reutilizable (Manana, Tarde, Noche, Guardia 12h...).
   TurnoCruzaMedianoche es calculada y persistida: resuelve los turnos nocturnos
   19:00 -> 07:00 que el modelo anterior no podia representar. */
IF OBJECT_ID(N'Configuracion.Turno', N'U') IS NULL
CREATE TABLE Configuracion.Turno (
    TurnoId                       INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Turno PRIMARY KEY,
    TipoJornadaId                 INT NOT NULL,
    TablaToleranciaId             INT NULL,
    TurnoCodigo                   NVARCHAR(30)  NOT NULL,
    TurnoNombre                   NVARCHAR(100) NOT NULL,
    TurnoHoraEntrada              TIME(0) NOT NULL,
    TurnoHoraSalida               TIME(0) NOT NULL,
    TurnoCruzaMedianoche AS (CASE WHEN TurnoHoraSalida <= TurnoHoraEntrada
                                  THEN CONVERT(BIT,1) ELSE CONVERT(BIT,0) END) PERSISTED,
    TurnoDuracionMinutos AS (CASE WHEN TurnoHoraSalida > TurnoHoraEntrada
                                  THEN DATEDIFF(MINUTE, TurnoHoraEntrada, TurnoHoraSalida)
                                  ELSE 1440 - DATEDIFF(MINUTE, TurnoHoraSalida, TurnoHoraEntrada) END) PERSISTED,
    TurnoToleranciaEntradaMinutos INT NOT NULL CONSTRAINT DF_TurnoToleranciaEntrada DEFAULT (0),
    TurnoToleranciaSalidaMinutos  INT NOT NULL CONSTRAINT DF_TurnoToleranciaSalida  DEFAULT (0),
    TurnoRefrigerioMinutos        INT NOT NULL CONSTRAINT DF_TurnoRefrigerioMinutos DEFAULT (0),
    TurnoPermiteHoraExtra         BIT NOT NULL CONSTRAINT DF_TurnoPermiteHoraExtra  DEFAULT (0),
    TurnoEsGuardia                BIT NOT NULL CONSTRAINT DF_TurnoEsGuardia         DEFAULT (0),
    TurnoEstado                   BIT NOT NULL CONSTRAINT DF_TurnoEstado            DEFAULT (1),
    CONSTRAINT FK_Turno_TipoJornada FOREIGN KEY (TipoJornadaId)
        REFERENCES Configuracion.TipoJornada(TipoJornadaId),
    CONSTRAINT FK_Turno_TablaTolerancia FOREIGN KEY (TablaToleranciaId)
        REFERENCES Configuracion.TablaTolerancia(TablaToleranciaId),
    CONSTRAINT UQ_TurnoCodigo UNIQUE (TurnoCodigo),
    CONSTRAINT UQ_TurnoNombre UNIQUE (TurnoNombre),
    CONSTRAINT CK_TurnoToleranciaEntrada CHECK (TurnoToleranciaEntradaMinutos >= 0),
    CONSTRAINT CK_TurnoToleranciaSalida  CHECK (TurnoToleranciaSalidaMinutos  >= 0),
    CONSTRAINT CK_TurnoRefrigerio        CHECK (TurnoRefrigerioMinutos        >= 0)
);
GO

/* ------------------------------------------------------------------------------
   HORARIO  (NUEVO)  -  responde al punto 3 del encargo
   ------------------------------------------------------------------------------
   Un HORARIO es una PLANTILLA reutilizable compuesta por MUCHOS TURNOS
   distribuidos en los dias de la semana.
   Se asigna a los trabajadores via Personal.AsignacionHorario (con vigencia).

   Relacion resultante:   Trabajador -> AsignacionHorario -> Horario -> Turno
   NO existe ya la relacion directa Trabajador -> Turno.

   DISTINCION IMPORTANTE
     Horario     = patron base recurrente (semanal), estable en el tiempo.
     Programacion= turnos reales de un periodo concreto por EESS (esquema Programacion).
   El personal administrativo se rige por su Horario; el asistencial rotativo se
   rige por la Programacion del periodo, que puede apartarse del Horario base.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Configuracion.Horario', N'U') IS NULL
CREATE TABLE Configuracion.Horario (
    HorarioId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Horario PRIMARY KEY,
    TipoJornadaId      INT NOT NULL,
    EessId             INT NULL,               -- NULL = horario institucional de toda la Red
    HorarioCodigo      NVARCHAR(30)  NOT NULL,
    HorarioNombre      NVARCHAR(150) NOT NULL,
    HorarioDescripcion NVARCHAR(300) NULL,
    HorarioEsRotativo  BIT NOT NULL CONSTRAINT DF_HorarioEsRotativo DEFAULT (0),
    HorarioEstado      BIT NOT NULL CONSTRAINT DF_HorarioEstado     DEFAULT (1),
    CONSTRAINT FK_Horario_TipoJornada FOREIGN KEY (TipoJornadaId)
        REFERENCES Configuracion.TipoJornada(TipoJornadaId),
    CONSTRAINT FK_Horario_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT UQ_HorarioCodigo UNIQUE (HorarioCodigo),
    CONSTRAINT UQ_HorarioNombre UNIQUE (HorarioNombre)
);
GO

/* Detalle del horario: que TURNO le corresponde a cada DIA de la semana.
   Un mismo dia admite mas de un turno (bloque partido), por eso el UNIQUE
   incluye TurnoId. */
IF OBJECT_ID(N'Configuracion.HorarioDetalle', N'U') IS NULL
CREATE TABLE Configuracion.HorarioDetalle (
    HorarioDetalleId     INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_HorarioDetalle PRIMARY KEY,
    HorarioId            INT NOT NULL,
    TurnoId              INT NOT NULL,
    HorarioDetalleDia    TINYINT NOT NULL,     -- 1=Lunes ... 7=Domingo (ISO-8601)
    HorarioDetalleOrden  TINYINT NOT NULL CONSTRAINT DF_HorarioDetalleOrden DEFAULT (1),
    HorarioDetalleEsDescanso BIT NOT NULL CONSTRAINT DF_HorarioDetalleEsDescanso DEFAULT (0),
    CONSTRAINT FK_HorarioDetalle_Horario FOREIGN KEY (HorarioId)
        REFERENCES Configuracion.Horario(HorarioId),
    CONSTRAINT FK_HorarioDetalle_Turno FOREIGN KEY (TurnoId)
        REFERENCES Configuracion.Turno(TurnoId),
    CONSTRAINT UQ_HorarioDetalle UNIQUE (HorarioId, HorarioDetalleDia, TurnoId),
    CONSTRAINT CK_HorarioDetalleDia CHECK (HorarioDetalleDia BETWEEN 1 AND 7)
);
GO

IF OBJECT_ID(N'Configuracion.ParametroSistema', N'U') IS NULL
CREATE TABLE Configuracion.ParametroSistema (
    ParametroSistemaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ParametroSistema PRIMARY KEY,
    ParametroSistemaCodigo      NVARCHAR(100) NOT NULL,
    ParametroSistemaValor       NVARCHAR(500) NULL,
    ParametroSistemaDescripcion NVARCHAR(300) NULL,
    ParametroSistemaEstado      BIT NOT NULL CONSTRAINT DF_ParametroSistemaEstado DEFAULT (1),
    CONSTRAINT UQ_ParametroSistemaCodigo UNIQUE (ParametroSistemaCodigo)
);
GO

/* ================================================================================
   05. PERSONAL
   --------------------------------------------------------------------------------
   Separacion explicita de los seis conceptos que no deben confundirse:
     RegimenLaboral    -> marco legal del contrato (D.L. 276 / 728 / 1057)
     CondicionLaboral  -> situacion del vinculo (Nombrado, Contratado, CAS, SERUMS)
     Cargo             -> puesto que ocupa
     GrupoOcupacional  -> categoria a la que pertenece el cargo
     Profesion         -> formacion academica del trabajador
     Colegiatura       -> registro ante el colegio profesional
   ================================================================================ */
IF OBJECT_ID(N'Personal.TipoDocumentoIdentidad', N'U') IS NULL
CREATE TABLE Personal.TipoDocumentoIdentidad (
    TipoDocumentoIdentidadId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoDocumentoIdentidad PRIMARY KEY,
    TipoDocumentoIdentidadCodigo      NVARCHAR(20)  NOT NULL,
    TipoDocumentoIdentidadNombre      NVARCHAR(100) NOT NULL,
    TipoDocumentoIdentidadAbreviatura NVARCHAR(20)  NULL,
    TipoDocumentoIdentidadLongitud    TINYINT       NULL,
    TipoDocumentoIdentidadEstado      BIT NOT NULL CONSTRAINT DF_TipoDocumentoIdentidadEstado DEFAULT (1),
    CONSTRAINT UQ_TipoDocumentoIdentidadCodigo UNIQUE (TipoDocumentoIdentidadCodigo),
    CONSTRAINT UQ_TipoDocumentoIdentidadNombre UNIQUE (TipoDocumentoIdentidadNombre)
);
GO

/* REGIMEN LABORAL: marco legal. NO confundir con CondicionLaboral. */
IF OBJECT_ID(N'Personal.RegimenLaboral', N'U') IS NULL
CREATE TABLE Personal.RegimenLaboral (
    RegimenLaboralId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RegimenLaboral PRIMARY KEY,
    RegimenLaboralCodigo      NVARCHAR(30)  NOT NULL,
    RegimenLaboralNombre      NVARCHAR(100) NOT NULL,
    RegimenLaboralBaseLegal   NVARCHAR(150) NULL,
    RegimenLaboralDescripcion NVARCHAR(250) NULL,
    RegimenLaboralEstado      BIT NOT NULL CONSTRAINT DF_RegimenLaboralEstado DEFAULT (1),
    CONSTRAINT UQ_RegimenLaboralCodigo UNIQUE (RegimenLaboralCodigo),
    CONSTRAINT UQ_RegimenLaboralNombre UNIQUE (RegimenLaboralNombre)
);
GO

/* CONDICION LABORAL (punto 4 del encargo).
   YA EXISTIA en el modelo original y YA estaba correctamente colgada del
   VINCULO LABORAL y no del Trabajador. Se REUTILIZA, no se duplica.
   Justificacion de la ubicacion: un mismo trabajador puede ser CONTRATADO hoy y
   NOMBRADO manana; la condicion califica al vinculo, no a la persona.
   Solo se le agregan Codigo y atributos funcionales. */
IF OBJECT_ID(N'Personal.CondicionLaboral', N'U') IS NULL
CREATE TABLE Personal.CondicionLaboral (
    CondicionLaboralId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CondicionLaboral PRIMARY KEY,
    CondicionLaboralCodigo      NVARCHAR(30)  NOT NULL,
    CondicionLaboralNombre      NVARCHAR(100) NOT NULL,
    CondicionLaboralDescripcion NVARCHAR(250) NULL,
    CondicionLaboralEsPermanente BIT NOT NULL CONSTRAINT DF_CondicionLaboralEsPermanente DEFAULT (0),
    CondicionLaboralRequiereAirhsp BIT NOT NULL CONSTRAINT DF_CondicionLaboralRequiereAirhsp DEFAULT (0),
    CondicionLaboralEstado      BIT NOT NULL CONSTRAINT DF_CondicionLaboralEstado DEFAULT (1),
    CONSTRAINT UQ_CondicionLaboralCodigo UNIQUE (CondicionLaboralCodigo),
    CONSTRAINT UQ_CondicionLaboralNombre UNIQUE (CondicionLaboralNombre)
);
GO

IF OBJECT_ID(N'Personal.GrupoOcupacional', N'U') IS NULL
CREATE TABLE Personal.GrupoOcupacional (
    GrupoOcupacionalId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_GrupoOcupacional PRIMARY KEY,
    GrupoOcupacionalCodigo      NVARCHAR(30)  NOT NULL,
    GrupoOcupacionalNombre      NVARCHAR(100) NOT NULL,
    GrupoOcupacionalDescripcion NVARCHAR(250) NULL,
    GrupoOcupacionalEstado      BIT NOT NULL CONSTRAINT DF_GrupoOcupacionalEstado DEFAULT (1),
    CONSTRAINT UQ_GrupoOcupacionalCodigo UNIQUE (GrupoOcupacionalCodigo),
    CONSTRAINT UQ_GrupoOcupacionalNombre UNIQUE (GrupoOcupacionalNombre)
);
GO

/* PROFESION (NUEVA). Antes no existia: el modelo solo tenia Cargo, lo que obligaba
   a mezclar "puesto" con "formacion". Ahora son entidades distintas. */
IF OBJECT_ID(N'Personal.Profesion', N'U') IS NULL
CREATE TABLE Personal.Profesion (
    ProfesionId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Profesion PRIMARY KEY,
    ProfesionCodigo      NVARCHAR(30)  NOT NULL,
    ProfesionNombre      NVARCHAR(150) NOT NULL,
    ProfesionDescripcion NVARCHAR(300) NULL,
    ProfesionRequiereColegiatura BIT NOT NULL CONSTRAINT DF_ProfesionRequiereColegiatura DEFAULT (0),
    ProfesionEstado      BIT NOT NULL CONSTRAINT DF_ProfesionEstado DEFAULT (1),
    CONSTRAINT UQ_ProfesionCodigo UNIQUE (ProfesionCodigo),
    CONSTRAINT UQ_ProfesionNombre UNIQUE (ProfesionNombre)
);
GO

/* CARGO = puesto. NO almacena colegiatura ni profesion (regla 5 del encargo). */
IF OBJECT_ID(N'Personal.Cargo', N'U') IS NULL
CREATE TABLE Personal.Cargo (
    CargoId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Cargo PRIMARY KEY,
    GrupoOcupacionalId INT NOT NULL,
    CargoCodigo        NVARCHAR(30)  NULL,
    CargoNombre        NVARCHAR(150) NOT NULL,
    CargoDescripcion   NVARCHAR(300) NULL,
    CargoEsJefatura    BIT NOT NULL CONSTRAINT DF_CargoEsJefatura DEFAULT (0),
    CargoEstado        BIT NOT NULL CONSTRAINT DF_CargoEstado     DEFAULT (1),
    CONSTRAINT FK_Cargo_GrupoOcupacional FOREIGN KEY (GrupoOcupacionalId)
        REFERENCES Personal.GrupoOcupacional(GrupoOcupacionalId),
    CONSTRAINT UQ_CargoNombre UNIQUE (CargoNombre)
    /* CargoCodigo es nulable -> su unicidad se resuelve con indice filtrado (seccion 19) */
);
GO

/* TIPO DE COLEGIATURA (NUEVA): CMP, CEP, COP, CQFP, CTP, CBP, CNP...
   Se enlaza opcionalmente con la Profesion que habilita. */
IF OBJECT_ID(N'Personal.ColegiaturaTipo', N'U') IS NULL
CREATE TABLE Personal.ColegiaturaTipo (
    ColegiaturaTipoId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ColegiaturaTipo PRIMARY KEY,
    ProfesionId                INT NULL,
    ColegiaturaTipoCodigo      NVARCHAR(20)  NOT NULL,   -- CMP, CEP, COP...
    ColegiaturaTipoNombre      NVARCHAR(150) NOT NULL,
    ColegiaturaTipoEntidad     NVARCHAR(200) NULL,       -- Colegio Medico del Peru
    ColegiaturaTipoDescripcion NVARCHAR(300) NULL,
    ColegiaturaTipoEstado      BIT NOT NULL CONSTRAINT DF_ColegiaturaTipoEstado DEFAULT (1),
    CONSTRAINT FK_ColegiaturaTipo_Profesion FOREIGN KEY (ProfesionId)
        REFERENCES Personal.Profesion(ProfesionId),
    CONSTRAINT UQ_ColegiaturaTipoCodigo UNIQUE (ColegiaturaTipoCodigo),
    CONSTRAINT UQ_ColegiaturaTipoNombre UNIQUE (ColegiaturaTipoNombre)
);
GO

IF OBJECT_ID(N'Personal.Trabajador', N'U') IS NULL
CREATE TABLE Personal.Trabajador (
    TrabajadorId              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Trabajador PRIMARY KEY,
    TipoDocumentoIdentidadId  INT NOT NULL,
    ProfesionId               INT NULL,        -- profesion principal; NULL para no profesionales
    TrabajadorNumeroDocumento NVARCHAR(30)  NOT NULL,
    TrabajadorNombres         NVARCHAR(100) NOT NULL,
    TrabajadorApellidoPaterno NVARCHAR(100) NOT NULL,
    TrabajadorApellidoMaterno NVARCHAR(100) NULL,
    TrabajadorNombreCompleto AS (
        TrabajadorApellidoPaterno + N' ' + ISNULL(TrabajadorApellidoMaterno, N'') + N', ' + TrabajadorNombres) PERSISTED,
    TrabajadorSexo            CHAR(1) NULL,
    TrabajadorFechaNacimiento DATE NULL,
    TrabajadorCorreo          NVARCHAR(200) NULL,
    TrabajadorTelefono        NVARCHAR(30)  NULL,
    TrabajadorDireccion       NVARCHAR(300) NULL,
    TrabajadorFotoRuta        NVARCHAR(500) NULL,   -- referencia externa, nunca binario
    TrabajadorFechaRegistro   DATETIME2(0) NOT NULL CONSTRAINT DF_TrabajadorFechaRegistro DEFAULT (SYSDATETIME()),
    TrabajadorEstado          BIT NOT NULL CONSTRAINT DF_TrabajadorEstado DEFAULT (1),
    CONSTRAINT FK_Trabajador_TipoDocumento FOREIGN KEY (TipoDocumentoIdentidadId)
        REFERENCES Personal.TipoDocumentoIdentidad(TipoDocumentoIdentidadId),
    CONSTRAINT FK_Trabajador_Profesion FOREIGN KEY (ProfesionId)
        REFERENCES Personal.Profesion(ProfesionId),
    CONSTRAINT UQ_TrabajadorDocumento UNIQUE (TipoDocumentoIdentidadId, TrabajadorNumeroDocumento),
    CONSTRAINT CK_TrabajadorSexo CHECK (TrabajadorSexo IS NULL OR TrabajadorSexo IN ('M','F'))
);
GO

/* COLEGIATURA (NUEVA) - punto 8 del encargo.
   Relacion:  Trabajador -> Colegiatura -> ColegiaturaTipo
   Un trabajador puede tener VARIAS colegiaturas (p.ej. CMP + CMP de especialidad,
   o un tecnologo con dos registros), pero solo UNA por cada colegio profesional.
   NUNCA se almacena en Personal.Cargo. */
IF OBJECT_ID(N'Personal.Colegiatura', N'U') IS NULL
CREATE TABLE Personal.Colegiatura (
    ColegiaturaId                 INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Colegiatura PRIMARY KEY,
    TrabajadorId                  INT NOT NULL,
    ColegiaturaTipoId             INT NOT NULL,
    DocumentoSustentoId           BIGINT NULL,
    ColegiaturaNumero             NVARCHAR(30) NOT NULL,
    ColegiaturaFechaColegiatura   DATE NULL,
    ColegiaturaFechaHabilitacion  DATE NULL,
    ColegiaturaFechaVencimiento   DATE NULL,
    ColegiaturaEsHabilitado       BIT NOT NULL CONSTRAINT DF_ColegiaturaEsHabilitado DEFAULT (1),
    ColegiaturaEsPrincipal        BIT NOT NULL CONSTRAINT DF_ColegiaturaEsPrincipal  DEFAULT (0),
    ColegiaturaObservacion        NVARCHAR(500) NULL,
    ColegiaturaEstado             BIT NOT NULL CONSTRAINT DF_ColegiaturaEstado DEFAULT (1),
    CONSTRAINT FK_Colegiatura_Trabajador FOREIGN KEY (TrabajadorId)
        REFERENCES Personal.Trabajador(TrabajadorId),
    CONSTRAINT FK_Colegiatura_ColegiaturaTipo FOREIGN KEY (ColegiaturaTipoId)
        REFERENCES Personal.ColegiaturaTipo(ColegiaturaTipoId),
    CONSTRAINT FK_Colegiatura_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT UQ_ColegiaturaNumero      UNIQUE (ColegiaturaTipoId, ColegiaturaNumero),
    CONSTRAINT UQ_ColegiaturaTrabajador  UNIQUE (TrabajadorId, ColegiaturaTipoId),
    CONSTRAINT CK_ColegiaturaFechas CHECK (
        ColegiaturaFechaVencimiento IS NULL OR ColegiaturaFechaHabilitacion IS NULL OR
        ColegiaturaFechaVencimiento >= ColegiaturaFechaHabilitacion)
);
GO

/* VINCULO LABORAL - eje transversal del sistema.
   CAMBIOS RESPECTO DE LA V1:
     (-) TurnoId          : eliminado. El turno ya no cuelga del contrato;
                            ahora se resuelve por AsignacionHorario -> Horario -> Turno
                            y por la Programacion del periodo.
     (+) CodigoAirhsp     : identificador AIRHSP del vinculo (punto 4 del encargo).
                            Va aqui y no en Trabajador porque el AIRHSP registra el
                            binomio plaza-persona, no a la persona en abstracto.
     (+) NumeroPlaza, EsProvisional, MotivoCese */
IF OBJECT_ID(N'Personal.VinculoLaboral', N'U') IS NULL
CREATE TABLE Personal.VinculoLaboral (
    VinculoLaboralId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_VinculoLaboral PRIMARY KEY,
    TrabajadorId              INT NOT NULL,
    EessId                    INT NOT NULL,     -- EESS donde labora
    RegimenLaboralId          INT NOT NULL,     -- marco legal
    CondicionLaboralId        INT NOT NULL,     -- Nombrado / Contratado / CAS / SERUMS
    CargoId                   INT NOT NULL,     -- puesto
    VinculoLaboralCodigo      NVARCHAR(50)  NULL,
    VinculoLaboralCodigoAirhsp NVARCHAR(20) NULL,   -- <-- AIRHSP
    VinculoLaboralNumeroPlaza NVARCHAR(30)  NULL,
    VinculoLaboralFechaInicio DATE NOT NULL,
    VinculoLaboralFechaFin    DATE NULL,
    VinculoLaboralMotivoCese  NVARCHAR(300) NULL,
    VinculoLaboralEstado      BIT NOT NULL CONSTRAINT DF_VinculoLaboralEstado DEFAULT (1),
    CONSTRAINT FK_VinculoLaboral_Trabajador FOREIGN KEY (TrabajadorId)
        REFERENCES Personal.Trabajador(TrabajadorId),
    CONSTRAINT FK_VinculoLaboral_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_VinculoLaboral_RegimenLaboral FOREIGN KEY (RegimenLaboralId)
        REFERENCES Personal.RegimenLaboral(RegimenLaboralId),
    CONSTRAINT FK_VinculoLaboral_CondicionLaboral FOREIGN KEY (CondicionLaboralId)
        REFERENCES Personal.CondicionLaboral(CondicionLaboralId),
    CONSTRAINT FK_VinculoLaboral_Cargo FOREIGN KEY (CargoId)
        REFERENCES Personal.Cargo(CargoId),
    CONSTRAINT CK_VinculoLaboralFechas CHECK (
        VinculoLaboralFechaFin IS NULL OR VinculoLaboralFechaFin >= VinculoLaboralFechaInicio)
    /* VinculoLaboralCodigo y CodigoAirhsp son nulables -> indices unicos filtrados */
);
GO

/* ASIGNACION DE HORARIO (NUEVA)
   Materializa  Trabajador -> Horario  con VIGENCIA, conservando el historico.
   Un indice unico filtrado (seccion 19) garantiza UN SOLO horario vigente por vinculo. */
IF OBJECT_ID(N'Personal.AsignacionHorario', N'U') IS NULL
CREATE TABLE Personal.AsignacionHorario (
    AsignacionHorarioId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AsignacionHorario PRIMARY KEY,
    VinculoLaboralId               INT NOT NULL,
    HorarioId                      INT NOT NULL,
    AsignacionHorarioFechaInicio   DATE NOT NULL,
    AsignacionHorarioFechaFin      DATE NULL,
    AsignacionHorarioObservacion   NVARCHAR(500) NULL,
    AsignacionHorarioFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_AsignacionHorarioFechaRegistro DEFAULT (SYSDATETIME()),
    AsignacionHorarioEstado        BIT NOT NULL CONSTRAINT DF_AsignacionHorarioEstado DEFAULT (1),
    CONSTRAINT FK_AsignacionHorario_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_AsignacionHorario_Horario FOREIGN KEY (HorarioId)
        REFERENCES Configuracion.Horario(HorarioId),
    CONSTRAINT UQ_AsignacionHorario UNIQUE (VinculoLaboralId, HorarioId, AsignacionHorarioFechaInicio),
    CONSTRAINT CK_AsignacionHorarioFechas CHECK (
        AsignacionHorarioFechaFin IS NULL OR AsignacionHorarioFechaFin >= AsignacionHorarioFechaInicio)
);
GO

/* ================================================================================
   06. ORGANIZACION (DEPENDIENTE) - RESPONSABLE DEL PERSONAL DE CADA EESS
   --------------------------------------------------------------------------------
   Punto 5 del encargo. Se descarto la columna EsResponsable BIT porque no permite
   saber DE QUE EESS se es responsable, y se descarto poner la FK dentro del propio
   EESS porque no conservaria el historico de designaciones.

   Permite responder:
     - quien es el responsable vigente de un EESS
     - a que EESS corresponde esa responsabilidad
     - a que Microred pertenece ese EESS  (via EstablecimientoSalud.MicroredId)
     - que trabajador desempena la funcion (via VinculoLaboral -> Trabajador)
     - quienes fueron los responsables anteriores (FechaFin NOT NULL)

   Si mas adelante necesita registrar al jefe de una Microred completa, cree una
   tabla ResponsableMicrored con esta misma forma cambiando EessId por MicroredId.
   ================================================================================ */
IF OBJECT_ID(N'Organizacion.ResponsableEess', N'U') IS NULL
CREATE TABLE Organizacion.ResponsableEess (
    ResponsableEessId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ResponsableEess PRIMARY KEY,
    EessId                       INT NOT NULL,
    VinculoLaboralId             INT NOT NULL,
    TipoResponsabilidadId        INT NOT NULL,
    DocumentoSustentoId          BIGINT NULL,      -- resolucion / memorando de designacion
    ResponsableEessFechaInicio   DATE NOT NULL,
    ResponsableEessFechaFin      DATE NULL,        -- NULL = vigente
    ResponsableEessDocumentoNumero NVARCHAR(60) NULL,
    ResponsableEessObservacion   NVARCHAR(500) NULL,
    ResponsableEessFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_ResponsableEessFechaRegistro DEFAULT (SYSDATETIME()),
    ResponsableEessEstado        BIT NOT NULL CONSTRAINT DF_ResponsableEessEstado DEFAULT (1),
    CONSTRAINT FK_ResponsableEess_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_ResponsableEess_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_ResponsableEess_TipoResponsabilidad FOREIGN KEY (TipoResponsabilidadId)
        REFERENCES Organizacion.TipoResponsabilidad(TipoResponsabilidadId),
    CONSTRAINT FK_ResponsableEess_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT UQ_ResponsableEess UNIQUE (EessId, TipoResponsabilidadId, VinculoLaboralId, ResponsableEessFechaInicio),
    CONSTRAINT CK_ResponsableEessFechas CHECK (
        ResponsableEessFechaFin IS NULL OR ResponsableEessFechaFin >= ResponsableEessFechaInicio)
);
GO

/* ================================================================================
   07. SEGURIDAD
   ================================================================================ */
IF OBJECT_ID(N'Seguridad.Permiso', N'U') IS NULL
CREATE TABLE Seguridad.Permiso (
    PermisoId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Permiso PRIMARY KEY,
    PermisoCodigo      NVARCHAR(100) NOT NULL,
    PermisoNombre      NVARCHAR(150) NOT NULL,
    PermisoModulo      NVARCHAR(60)  NULL,
    PermisoDescripcion NVARCHAR(300) NULL,
    PermisoEstado      BIT NOT NULL CONSTRAINT DF_PermisoEstado DEFAULT (1),
    CONSTRAINT UQ_PermisoCodigo UNIQUE (PermisoCodigo),
    CONSTRAINT UQ_PermisoNombre UNIQUE (PermisoNombre)
);
GO

IF OBJECT_ID(N'Seguridad.Rol', N'U') IS NULL
CREATE TABLE Seguridad.Rol (
    RolId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Rol PRIMARY KEY,
    RolCodigo      NVARCHAR(50)  NOT NULL,
    RolNombre      NVARCHAR(100) NOT NULL,
    RolDescripcion NVARCHAR(300) NULL,
    RolEstado      BIT NOT NULL CONSTRAINT DF_RolEstado DEFAULT (1),
    CONSTRAINT UQ_RolCodigo UNIQUE (RolCodigo),
    CONSTRAINT UQ_RolNombre UNIQUE (RolNombre)
);
GO

IF OBJECT_ID(N'Seguridad.RolPermiso', N'U') IS NULL
CREATE TABLE Seguridad.RolPermiso (
    RolPermisoId     INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RolPermiso PRIMARY KEY,
    RolId            INT NOT NULL,
    PermisoId        INT NOT NULL,
    RolPermisoEstado BIT NOT NULL CONSTRAINT DF_RolPermisoEstado DEFAULT (1),
    CONSTRAINT FK_RolPermiso_Rol     FOREIGN KEY (RolId)     REFERENCES Seguridad.Rol(RolId),
    CONSTRAINT FK_RolPermiso_Permiso FOREIGN KEY (PermisoId) REFERENCES Seguridad.Permiso(PermisoId),
    CONSTRAINT UQ_RolPermiso UNIQUE (RolId, PermisoId)
);
GO

IF OBJECT_ID(N'Seguridad.Usuario', N'U') IS NULL
CREATE TABLE Seguridad.Usuario (
    UsuarioId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Usuario PRIMARY KEY,
    TrabajadorId         INT NOT NULL,
    UsuarioNombre        NVARCHAR(100) NOT NULL,
    UsuarioPasswordHash  NVARCHAR(500) NOT NULL,
    /* Correo INSTITUCIONAL de la cuenta (login y notificaciones).
       Se distingue deliberadamente de Trabajador.TrabajadorCorreo, que es el
       correo personal de contacto. No es redundancia: son dos hechos distintos. */
    UsuarioCorreo        NVARCHAR(200) NULL,
    UsuarioFechaCreacion DATETIME2(0) NOT NULL CONSTRAINT DF_UsuarioFechaCreacion DEFAULT (SYSDATETIME()),
    UsuarioEstado        BIT NOT NULL CONSTRAINT DF_UsuarioEstado DEFAULT (1),
    CONSTRAINT FK_Usuario_Trabajador FOREIGN KEY (TrabajadorId) REFERENCES Personal.Trabajador(TrabajadorId),
    CONSTRAINT UQ_UsuarioNombre     UNIQUE (UsuarioNombre),
    CONSTRAINT UQ_UsuarioTrabajador UNIQUE (TrabajadorId)
);
GO

IF OBJECT_ID(N'Seguridad.UsuarioRol', N'U') IS NULL
CREATE TABLE Seguridad.UsuarioRol (
    UsuarioRolId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_UsuarioRol PRIMARY KEY,
    UsuarioId             INT NOT NULL,
    RolId                 INT NOT NULL,
    UsuarioRolFechaInicio DATE NOT NULL CONSTRAINT DF_UsuarioRolFechaInicio DEFAULT (CONVERT(date, SYSDATETIME())),
    UsuarioRolFechaFin    DATE NULL,
    UsuarioRolEstado      BIT NOT NULL CONSTRAINT DF_UsuarioRolEstado DEFAULT (1),
    CONSTRAINT FK_UsuarioRol_Usuario FOREIGN KEY (UsuarioId) REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_UsuarioRol_Rol     FOREIGN KEY (RolId)     REFERENCES Seguridad.Rol(RolId),
    CONSTRAINT UQ_UsuarioRol UNIQUE (UsuarioId, RolId),
    CONSTRAINT CK_UsuarioRolFechas CHECK (
        UsuarioRolFechaFin IS NULL OR UsuarioRolFechaFin >= UsuarioRolFechaInicio)
);
GO

/* Ambito de visibilidad del usuario. Tres alcances posibles, sin recursividad:
     MicroredId NOT NULL, EessId NULL  -> ve toda la Microred y sus EESS
     EessId     NOT NULL               -> ve un unico EESS
     ambos NULL                        -> ve toda la Red (perfil de sede) */
IF OBJECT_ID(N'Seguridad.UsuarioAmbito', N'U') IS NULL
CREATE TABLE Seguridad.UsuarioAmbito (
    UsuarioAmbitoId      INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_UsuarioAmbito PRIMARY KEY,
    UsuarioId            INT NOT NULL,
    MicroredId           INT NULL,
    EessId               INT NULL,
    UsuarioAmbitoEstado  BIT NOT NULL CONSTRAINT DF_UsuarioAmbitoEstado DEFAULT (1),
    CONSTRAINT FK_UsuarioAmbito_Usuario  FOREIGN KEY (UsuarioId)  REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_UsuarioAmbito_Microred FOREIGN KEY (MicroredId) REFERENCES Organizacion.Microred(MicroredId),
    CONSTRAINT FK_UsuarioAmbito_Eess     FOREIGN KEY (EessId)     REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT UQ_UsuarioAmbito UNIQUE (UsuarioId, MicroredId, EessId),
    CONSTRAINT CK_UsuarioAmbitoAlcance CHECK (NOT (MicroredId IS NOT NULL AND EessId IS NOT NULL))
);
GO

IF OBJECT_ID(N'Seguridad.SesionAcceso', N'U') IS NULL
CREATE TABLE Seguridad.SesionAcceso (
    SesionAccesoId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_SesionAcceso PRIMARY KEY,
    UsuarioId               INT NOT NULL,
    SesionAccesoFechaInicio DATETIME2(0) NOT NULL CONSTRAINT DF_SesionAccesoFechaInicio DEFAULT (SYSDATETIME()),
    SesionAccesoFechaFin    DATETIME2(0) NULL,
    SesionAccesoDireccionIp NVARCHAR(45) NULL,
    SesionAccesoResultado   NVARCHAR(50) NULL,
    CONSTRAINT FK_SesionAcceso_Usuario FOREIGN KEY (UsuarioId) REFERENCES Seguridad.Usuario(UsuarioId)
);
GO

/* ================================================================================
   08. BIOMETRIA
   --------------------------------------------------------------------------------
   Nota: PlantillaBiometricaReferencia si usa VARBINARY, y es correcto. La regla 8
   del encargo prohibe VARBINARY para DOCUMENTOS (PDF escaneados); una plantilla
   biometrica es un vector de rasgos de pocos cientos de bytes, no un archivo.
   ================================================================================ */
IF OBJECT_ID(N'Biometria.MetodoMarcacion', N'U') IS NULL
CREATE TABLE Biometria.MetodoMarcacion (
    MetodoMarcacionId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_MetodoMarcacion PRIMARY KEY,
    MetodoMarcacionCodigo      NVARCHAR(50)  NOT NULL,
    MetodoMarcacionNombre      NVARCHAR(100) NOT NULL,
    MetodoMarcacionDescripcion NVARCHAR(250) NULL,
    MetodoMarcacionEstado      BIT NOT NULL CONSTRAINT DF_MetodoMarcacionEstado DEFAULT (1),
    CONSTRAINT UQ_MetodoMarcacionCodigo UNIQUE (MetodoMarcacionCodigo),
    CONSTRAINT UQ_MetodoMarcacionNombre UNIQUE (MetodoMarcacionNombre)
);
GO

/* (+) EessId: antes la ubicacion del dispositivo era texto libre y no se sabia
   a que EESS pertenecia. */
IF OBJECT_ID(N'Biometria.DispositivoMarcacion', N'U') IS NULL
CREATE TABLE Biometria.DispositivoMarcacion (
    DispositivoMarcacionId        INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DispositivoMarcacion PRIMARY KEY,
    EessId                        INT NULL,
    DispositivoMarcacionCodigo    NVARCHAR(50)  NOT NULL,
    DispositivoMarcacionNombre    NVARCHAR(100) NOT NULL,
    DispositivoMarcacionTipo      NVARCHAR(50)  NOT NULL,
    DispositivoMarcacionUbicacion NVARCHAR(200) NULL,
    DispositivoMarcacionIp        NVARCHAR(45)  NULL,
    DispositivoMarcacionEstado    BIT NOT NULL CONSTRAINT DF_DispositivoMarcacionEstado DEFAULT (1),
    CONSTRAINT FK_DispositivoMarcacion_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT UQ_DispositivoMarcacionCodigo UNIQUE (DispositivoMarcacionCodigo)
);
GO

IF OBJECT_ID(N'Biometria.PlantillaBiometrica', N'U') IS NULL
CREATE TABLE Biometria.PlantillaBiometrica (
    PlantillaBiometricaId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PlantillaBiometrica PRIMARY KEY,
    TrabajadorId                     INT NOT NULL,
    PlantillaBiometricaTipo          NVARCHAR(50) NOT NULL,
    PlantillaBiometricaDedo          NVARCHAR(30) NULL,
    PlantillaBiometricaReferencia    VARBINARY(MAX) NULL,
    PlantillaBiometricaFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_PlantillaBiometricaFechaRegistro DEFAULT (SYSDATETIME()),
    PlantillaBiometricaEstado        BIT NOT NULL CONSTRAINT DF_PlantillaBiometricaEstado DEFAULT (1),
    CONSTRAINT FK_PlantillaBiometrica_Trabajador FOREIGN KEY (TrabajadorId)
        REFERENCES Personal.Trabajador(TrabajadorId)
);
GO

IF OBJECT_ID(N'Biometria.AutorizacionMetodo', N'U') IS NULL
CREATE TABLE Biometria.AutorizacionMetodo (
    AutorizacionMetodoId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AutorizacionMetodo PRIMARY KEY,
    TrabajadorId                  INT NOT NULL,
    MetodoMarcacionId             INT NOT NULL,
    AutorizacionMetodoFechaInicio DATE NOT NULL,
    AutorizacionMetodoFechaFin    DATE NULL,
    AutorizacionMetodoEstado      BIT NOT NULL CONSTRAINT DF_AutorizacionMetodoEstado DEFAULT (1),
    CONSTRAINT FK_AutorizacionMetodo_Trabajador FOREIGN KEY (TrabajadorId)      REFERENCES Personal.Trabajador(TrabajadorId),
    CONSTRAINT FK_AutorizacionMetodo_Metodo     FOREIGN KEY (MetodoMarcacionId) REFERENCES Biometria.MetodoMarcacion(MetodoMarcacionId),
    CONSTRAINT UQ_AutorizacionMetodo UNIQUE (TrabajadorId, MetodoMarcacionId),
    CONSTRAINT CK_AutorizacionMetodoFechas CHECK (
        AutorizacionMetodoFechaFin IS NULL OR AutorizacionMetodoFechaFin >= AutorizacionMetodoFechaInicio)
);
GO

IF OBJECT_ID(N'Biometria.ConsentimientoBiometrico', N'U') IS NULL
CREATE TABLE Biometria.ConsentimientoBiometrico (
    ConsentimientoBiometricoId       BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ConsentimientoBiometrico PRIMARY KEY,
    TrabajadorId                     INT NOT NULL,
    DocumentoSustentoId              BIGINT NULL,
    ConsentimientoBiometricoFecha    DATETIME2(0) NOT NULL CONSTRAINT DF_ConsentimientoBiometricoFecha DEFAULT (SYSDATETIME()),
    ConsentimientoBiometricoAceptado BIT NOT NULL,
    ConsentimientoBiometricoVersion  NVARCHAR(30) NULL,
    CONSTRAINT FK_ConsentimientoBiometrico_Trabajador FOREIGN KEY (TrabajadorId)        REFERENCES Personal.Trabajador(TrabajadorId),
    CONSTRAINT FK_ConsentimientoBiometrico_Documento  FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId)
);
GO

/* ================================================================================
   09. SOPORTE (DEPENDIENTE)
   ================================================================================ */
/* (+) MicroredId: NULL = feriado de alcance nacional / toda la Red.
   Antes el ambito era texto libre y el UNIQUE global impedia coexistir un feriado
   nacional con uno regional en la misma fecha. */
IF OBJECT_ID(N'Soporte.CalendarioNoLaborable', N'U') IS NULL
CREATE TABLE Soporte.CalendarioNoLaborable (
    CalendarioNoLaborableId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CalendarioNoLaborable PRIMARY KEY,
    MicroredId                       INT NULL,
    CalendarioNoLaborableFecha       DATE NOT NULL,
    CalendarioNoLaborableTipo        NVARCHAR(30) NOT NULL,
    CalendarioNoLaborableDescripcion NVARCHAR(250) NULL,
    CalendarioNoLaborableCompensable BIT NOT NULL CONSTRAINT DF_CalendarioNoLaborableCompensable DEFAULT (0),
    CalendarioNoLaborableNormaSustento NVARCHAR(200) NULL,
    CONSTRAINT FK_CalendarioNoLaborable_Microred FOREIGN KEY (MicroredId)
        REFERENCES Organizacion.Microred(MicroredId),
    CONSTRAINT UQ_CalendarioNoLaborable UNIQUE (CalendarioNoLaborableFecha, MicroredId),
    CONSTRAINT CK_CalendarioNoLaborableTipo CHECK (
        CalendarioNoLaborableTipo IN (N'FERIADO', N'DIA_NO_LABORABLE', N'ASUETO', N'DUELO'))
);
GO

IF OBJECT_ID(N'Soporte.LogIntegracion', N'U') IS NULL
CREATE TABLE Soporte.LogIntegracion (
    LogIntegracionId             BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_LogIntegracion PRIMARY KEY,
    LogIntegracionSistemaExterno NVARCHAR(100) NOT NULL,
    LogIntegracionOperacion      NVARCHAR(100) NOT NULL,
    LogIntegracionDireccion      NVARCHAR(20)  NULL,
    LogIntegracionPayloadResumen NVARCHAR(1000) NULL,
    LogIntegracionResultado      NVARCHAR(50)  NULL,
    LogIntegracionMensajeError   NVARCHAR(2000) NULL,
    LogIntegracionFechaHora      DATETIME2(0) NOT NULL CONSTRAINT DF_LogIntegracionFechaHora DEFAULT (SYSDATETIME()),
    LogIntegracionReintentos     INT NOT NULL CONSTRAINT DF_LogIntegracionReintentos DEFAULT (0)
);
GO

IF OBJECT_ID(N'Soporte.Notificacion', N'U') IS NULL
CREATE TABLE Soporte.Notificacion (
    NotificacionId      BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Notificacion PRIMARY KEY,
    UsuarioId           INT NOT NULL,
    NotificacionTipo    NVARCHAR(50)   NOT NULL,
    NotificacionTitulo  NVARCHAR(200)  NOT NULL,
    NotificacionMensaje NVARCHAR(1000) NOT NULL,
    NotificacionEnlace  NVARCHAR(300)  NULL,
    NotificacionFecha   DATETIME2(0) NOT NULL CONSTRAINT DF_NotificacionFecha DEFAULT (SYSDATETIME()),
    NotificacionLeida   BIT NOT NULL CONSTRAINT DF_NotificacionLeida DEFAULT (0),
    CONSTRAINT FK_Notificacion_Usuario FOREIGN KEY (UsuarioId) REFERENCES Seguridad.Usuario(UsuarioId)
);
GO

/* ================================================================================
   10. PROGRAMACION  (reestructurada - puntos 9 y 10 del encargo)
   --------------------------------------------------------------------------------
   MODELO ANTERIOR (incorrecto):
       ProgramacionMensual (VinculoLaboralId, Anio, Mes) -> TurnoProgramado
       La programacion era POR PERSONA. No existia cabecera por EESS, no se podia
       aprobar ni cerrar el rol de turnos de un establecimiento, y Anio+Mes ataba
       el modelo a lo mensual.

   MODELO NUEVO (tres niveles):
       ProgramacionPeriodo     -> cabecera POR EESS y POR PERIODO
       ProgramacionTrabajador  -> fila de cada trabajador dentro de esa programacion
                                  (es la ProgramacionMensual anterior, renombrada)
       TurnoProgramado         -> turnos en fechas concretas

   Mensual, quincenal y semanal se resuelven con TipoPeriodoProgramacion +
   FechaInicio/FechaFin. NO se crean tablas separadas por tipo de periodo.
   ================================================================================ */
IF OBJECT_ID(N'Programacion.TipoPeriodoProgramacion', N'U') IS NULL
CREATE TABLE Programacion.TipoPeriodoProgramacion (
    TipoPeriodoProgramacionId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoPeriodoProgramacion PRIMARY KEY,
    TipoPeriodoProgramacionCodigo      NVARCHAR(30)  NOT NULL,
    TipoPeriodoProgramacionNombre      NVARCHAR(100) NOT NULL,
    TipoPeriodoProgramacionDias        SMALLINT      NULL,   -- referencial
    TipoPeriodoProgramacionDescripcion NVARCHAR(250) NULL,
    TipoPeriodoProgramacionEstado      BIT NOT NULL CONSTRAINT DF_TipoPeriodoProgramacionEstado DEFAULT (1),
    CONSTRAINT UQ_TipoPeriodoProgramacionCodigo UNIQUE (TipoPeriodoProgramacionCodigo),
    CONSTRAINT UQ_TipoPeriodoProgramacionNombre UNIQUE (TipoPeriodoProgramacionNombre)
);
GO

/* CABECERA: la programacion de un EESS para un periodo. Es el objeto que se
   elabora, publica y cierra. Aqui es donde se gestiona GLOBALMENTE por EESS. */
IF OBJECT_ID(N'Programacion.ProgramacionPeriodo', N'U') IS NULL
CREATE TABLE Programacion.ProgramacionPeriodo (
    ProgramacionPeriodoId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ProgramacionPeriodo PRIMARY KEY,
    EessId                           INT NOT NULL,           -- el EESS
    TipoPeriodoProgramacionId        INT NOT NULL,
    UsuarioRegistroId                INT NOT NULL,
    ProgramacionPeriodoCodigo        NVARCHAR(50)  NULL,
    ProgramacionPeriodoAnio          SMALLINT NOT NULL,
    ProgramacionPeriodoMes           TINYINT  NULL,          -- NULL si el tipo no es mensual/quincenal
    ProgramacionPeriodoNumero        TINYINT  NULL,          -- quincena 1|2, semana 1..5
    ProgramacionPeriodoFechaInicio   DATE NOT NULL,
    ProgramacionPeriodoFechaFin      DATE NOT NULL,
    ProgramacionPeriodoObservacion   NVARCHAR(1000) NULL,
    ProgramacionPeriodoFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_ProgramacionPeriodoFechaRegistro DEFAULT (SYSDATETIME()),
    ProgramacionPeriodoFechaPublicacion DATETIME2(0) NULL,
    ProgramacionPeriodoEstado        NVARCHAR(30) NOT NULL CONSTRAINT DF_ProgramacionPeriodoEstado DEFAULT (N'BORRADOR'),
    CONSTRAINT FK_ProgramacionPeriodo_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_ProgramacionPeriodo_TipoPeriodo FOREIGN KEY (TipoPeriodoProgramacionId)
        REFERENCES Programacion.TipoPeriodoProgramacion(TipoPeriodoProgramacionId),
    CONSTRAINT FK_ProgramacionPeriodo_Usuario FOREIGN KEY (UsuarioRegistroId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT UQ_ProgramacionPeriodo UNIQUE (EessId, TipoPeriodoProgramacionId, ProgramacionPeriodoFechaInicio),
    CONSTRAINT CK_ProgramacionPeriodoMes    CHECK (ProgramacionPeriodoMes IS NULL OR ProgramacionPeriodoMes BETWEEN 1 AND 12),
    CONSTRAINT CK_ProgramacionPeriodoFechas CHECK (ProgramacionPeriodoFechaFin >= ProgramacionPeriodoFechaInicio),
    CONSTRAINT CK_ProgramacionPeriodoEstado CHECK (
        ProgramacionPeriodoEstado IN (N'BORRADOR', N'PUBLICADA', N'CERRADA', N'ANULADA'))
);
GO

/* DETALLE POR TRABAJADOR. Sustituye a Programacion.ProgramacionMensual:
   misma granularidad (un trabajador dentro de un periodo), pero ahora colgando
   de la cabecera del EESS en lugar de existir aislada. */
IF OBJECT_ID(N'Programacion.ProgramacionTrabajador', N'U') IS NULL
CREATE TABLE Programacion.ProgramacionTrabajador (
    ProgramacionTrabajadorId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ProgramacionTrabajador PRIMARY KEY,
    ProgramacionPeriodoId             BIGINT NOT NULL,
    VinculoLaboralId                  INT NOT NULL,
    ProgramacionTrabajadorHorasProgramadas DECIMAL(7,2) NULL,
    ProgramacionTrabajadorObservacion NVARCHAR(500) NULL,
    ProgramacionTrabajadorEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_ProgramacionTrabajadorEstado DEFAULT (N'BORRADOR'),
    CONSTRAINT FK_ProgramacionTrabajador_Periodo FOREIGN KEY (ProgramacionPeriodoId)
        REFERENCES Programacion.ProgramacionPeriodo(ProgramacionPeriodoId),
    CONSTRAINT FK_ProgramacionTrabajador_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT UQ_ProgramacionTrabajador UNIQUE (ProgramacionPeriodoId, VinculoLaboralId),
    CONSTRAINT CK_ProgramacionTrabajadorEstado CHECK (
        ProgramacionTrabajadorEstado IN (N'BORRADOR', N'PUBLICADA', N'CERRADA', N'ANULADA'))
);
GO

/* TURNO PROGRAMADO.
   El UNIQUE ahora incluye TurnoId: un trabajador SI puede tener dos bloques el
   mismo dia (p.ej. guardia diurna + reten), cosa que el modelo anterior impedia. */
IF OBJECT_ID(N'Programacion.TurnoProgramado', N'U') IS NULL
CREATE TABLE Programacion.TurnoProgramado (
    TurnoProgramadoId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TurnoProgramado PRIMARY KEY,
    ProgramacionTrabajadorId   BIGINT NOT NULL,
    TurnoId                    INT NOT NULL,
    TurnoProgramadoFecha       DATE NOT NULL,
    TurnoProgramadoHoraEntrada TIME(0) NULL,   -- NULL = hereda las horas del Turno
    TurnoProgramadoHoraSalida  TIME(0) NULL,
    TurnoProgramadoEsGuardia   BIT NOT NULL CONSTRAINT DF_TurnoProgramadoEsGuardia DEFAULT (0),
    TurnoProgramadoObservacion NVARCHAR(500) NULL,
    TurnoProgramadoEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_TurnoProgramadoEstado DEFAULT (N'PROGRAMADO'),
    CONSTRAINT FK_TurnoProgramado_ProgramacionTrabajador FOREIGN KEY (ProgramacionTrabajadorId)
        REFERENCES Programacion.ProgramacionTrabajador(ProgramacionTrabajadorId),
    CONSTRAINT FK_TurnoProgramado_Turno FOREIGN KEY (TurnoId)
        REFERENCES Configuracion.Turno(TurnoId),
    CONSTRAINT UQ_TurnoProgramado UNIQUE (ProgramacionTrabajadorId, TurnoProgramadoFecha, TurnoId),
    CONSTRAINT CK_TurnoProgramadoEstado CHECK (
        TurnoProgramadoEstado IN (N'PROGRAMADO', N'REPROGRAMADO', N'CUMPLIDO', N'ANULADO'))
);
GO

/* ------------------------------------------------------------------------------
   CAMBIO DE TURNO UNIFICADO
   ------------------------------------------------------------------------------
   FUSIONA cuatro tablas del modelo anterior:
     Programacion.Reprogramacion            (estructura identica a CambioTurno)
     Programacion.CambioTurno
     Programacion.CambioTurnoSolicitante    (puente que permitia N solicitantes)
     Programacion.CambioTurnoReemplazante   (puente duplicado del anterior)

   Un cambio de turno tiene EXACTAMENTE un solicitante y a lo sumo un reemplazante.
   Modelarlo con tablas puente permitia estados imposibles. Ahora son columnas.
   El tipo distingue reprogramacion (la jefatura mueve el turno), permuta (dos
   trabajadores intercambian) y reemplazo (otro cubre el turno).
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Programacion.TipoCambioTurno', N'U') IS NULL
CREATE TABLE Programacion.TipoCambioTurno (
    TipoCambioTurnoId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoCambioTurno PRIMARY KEY,
    TipoCambioTurnoCodigo        NVARCHAR(30)  NOT NULL,
    TipoCambioTurnoNombre        NVARCHAR(100) NOT NULL,
    TipoCambioTurnoRequiereReemplazante BIT NOT NULL CONSTRAINT DF_TipoCambioTurnoRequiereReemp DEFAULT (0),
    TipoCambioTurnoDescripcion   NVARCHAR(250) NULL,
    TipoCambioTurnoEstado        BIT NOT NULL CONSTRAINT DF_TipoCambioTurnoEstado DEFAULT (1),
    CONSTRAINT UQ_TipoCambioTurnoCodigo UNIQUE (TipoCambioTurnoCodigo),
    CONSTRAINT UQ_TipoCambioTurnoNombre UNIQUE (TipoCambioTurnoNombre)
);
GO

IF OBJECT_ID(N'Programacion.CambioTurno', N'U') IS NULL
CREATE TABLE Programacion.CambioTurno (
    CambioTurnoId                  BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CambioTurno PRIMARY KEY,
    TipoCambioTurnoId              INT NOT NULL,
    TurnoProgramadoId              BIGINT NOT NULL,          -- turno afectado
    TurnoProgramadoContraparteId   BIGINT NULL,              -- turno del otro trabajador (permuta)
    TurnoIdNuevo                   INT NULL,                 -- turno resultante (reprogramacion)
    VinculoLaboralSolicitanteId    INT NOT NULL,
    VinculoLaboralReemplazanteId   INT NULL,
    DocumentoSustentoId            BIGINT NULL,
    UsuarioRegistroId              INT NOT NULL,
    UsuarioAprobacionId            INT NULL,
    CambioTurnoFechaSolicitud      DATETIME2(0) NOT NULL CONSTRAINT DF_CambioTurnoFechaSolicitud DEFAULT (SYSDATETIME()),
    CambioTurnoFechaResolucion     DATETIME2(0) NULL,
    CambioTurnoMotivo              NVARCHAR(1000) NULL,
    CambioTurnoObservacion         NVARCHAR(1000) NULL,
    CambioTurnoEstado              NVARCHAR(30) NOT NULL CONSTRAINT DF_CambioTurnoEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_CambioTurno_TipoCambioTurno FOREIGN KEY (TipoCambioTurnoId)
        REFERENCES Programacion.TipoCambioTurno(TipoCambioTurnoId),
    CONSTRAINT FK_CambioTurno_TurnoProgramado FOREIGN KEY (TurnoProgramadoId)
        REFERENCES Programacion.TurnoProgramado(TurnoProgramadoId),
    CONSTRAINT FK_CambioTurno_TurnoProgramadoContraparte FOREIGN KEY (TurnoProgramadoContraparteId)
        REFERENCES Programacion.TurnoProgramado(TurnoProgramadoId),
    CONSTRAINT FK_CambioTurno_TurnoNuevo FOREIGN KEY (TurnoIdNuevo)
        REFERENCES Configuracion.Turno(TurnoId),
    CONSTRAINT FK_CambioTurno_Solicitante FOREIGN KEY (VinculoLaboralSolicitanteId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_CambioTurno_Reemplazante FOREIGN KEY (VinculoLaboralReemplazanteId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_CambioTurno_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_CambioTurno_UsuarioRegistro FOREIGN KEY (UsuarioRegistroId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_CambioTurno_UsuarioAprobacion FOREIGN KEY (UsuarioAprobacionId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_CambioTurnoEstado CHECK (
        CambioTurnoEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO')),
    CONSTRAINT CK_CambioTurnoPersonas CHECK (
        VinculoLaboralReemplazanteId IS NULL OR VinculoLaboralReemplazanteId <> VinculoLaboralSolicitanteId),
    CONSTRAINT CK_CambioTurnoContraparte CHECK (
        TurnoProgramadoContraparteId IS NULL OR TurnoProgramadoContraparteId <> TurnoProgramadoId)
);
GO

/* (+) TurnoProgramadoId y DocumentoSustentoId: antes el informe de guardia
   comunitaria no se podia vincular al turno que lo origina ni a su sustento. */
IF OBJECT_ID(N'Programacion.InformeGuardiaComunitaria', N'U') IS NULL
CREATE TABLE Programacion.InformeGuardiaComunitaria (
    InformeGuardiaComunitariaId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_InformeGuardiaComunitaria PRIMARY KEY,
    VinculoLaboralId                     INT NOT NULL,
    TurnoProgramadoId                    BIGINT NULL,
    DocumentoSustentoId                  BIGINT NULL,
    InformeGuardiaComunitariaFecha       DATE NOT NULL,
    InformeGuardiaComunitariaHoraInicio  TIME(0) NULL,
    InformeGuardiaComunitariaHoraFin     TIME(0) NULL,
    InformeGuardiaComunitariaDescripcion NVARCHAR(1000) NULL,
    InformeGuardiaComunitariaEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_InformeGuardiaComunitariaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_InformeGuardiaComunitaria_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_InformeGuardiaComunitaria_TurnoProgramado FOREIGN KEY (TurnoProgramadoId)
        REFERENCES Programacion.TurnoProgramado(TurnoProgramadoId),
    CONSTRAINT FK_InformeGuardiaComunitaria_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_InformeGuardiaComunitariaEstado CHECK (
        InformeGuardiaComunitariaEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

/* ------------------------------------------------------------------------------
   CARGA DE PROGRAMACION  (NUEVA) - punto 9 del encargo
   ------------------------------------------------------------------------------
   FINALIDAD ESTRICTAMENTE DOCUMENTAL.

   Deja CONSTANCIA de que un EESS remitio su documento oficial de programacion
   (PDF escaneado, mensual o quincenal). NO representa turnos individuales.

   NO CONFUNDIR con:
     ProgramacionPeriodo    -> la programacion estructurada por EESS y periodo
     ProgramacionTrabajador -> la fila de cada trabajador
     TurnoProgramado        -> los turnos concretos en fechas

   El archivo NO se guarda en la base de datos: se referencia Soporte.DocumentoSustento,
   cuya columna DocumentoSustentoRuta apunta al almacenamiento externo. Sin VARBINARY.
   Se REUTILIZA la estructura documental existente en lugar de crear otra.

   El enlace con ProgramacionPeriodo es NULABLE y OPCIONAL: existe solo para que,
   si alguien digita despues la programacion estructurada, quede trazada contra su
   documento fuente. Ninguna regla del modelo convierte el PDF en turnos.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Programacion.CargaProgramacion', N'U') IS NULL
CREATE TABLE Programacion.CargaProgramacion (
    CargaProgramacionId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CargaProgramacion PRIMARY KEY,
    EessId                         INT NOT NULL,        -- EESS que remite
    DocumentoSustentoId            BIGINT NOT NULL,     -- el PDF escaneado (ruta externa)
    UsuarioRegistroId              INT NOT NULL,        -- quien realizo la carga
    TipoPeriodoProgramacionId      INT NULL,            -- MENSUAL / QUINCENAL
    ProgramacionPeriodoId          BIGINT NULL,         -- enlace OPCIONAL, nunca automatico
    CargaProgramacionCodigo        NVARCHAR(50) NOT NULL,  -- PROG-2026-09-001
    CargaProgramacionAnio          SMALLINT NOT NULL,
    CargaProgramacionMes           TINYINT  NOT NULL,
    CargaProgramacionNumero        TINYINT  NULL,       -- quincena 1|2
    CargaProgramacionFechaDocumento DATE NOT NULL,      -- fecha del documento (F/H)
    CargaProgramacionDocumentoNumero NVARCHAR(60) NULL, -- oficio/memorando de remision
    CargaProgramacionMotivo        NVARCHAR(500) NULL,
    CargaProgramacionObservacion   NVARCHAR(1000) NULL,
    CargaProgramacionFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_CargaProgramacionFechaRegistro DEFAULT (SYSDATETIME()),
    CargaProgramacionEstado        NVARCHAR(30) NOT NULL CONSTRAINT DF_CargaProgramacionEstado DEFAULT (N'REGISTRADO'),
    CONSTRAINT FK_CargaProgramacion_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_CargaProgramacion_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_CargaProgramacion_Usuario FOREIGN KEY (UsuarioRegistroId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_CargaProgramacion_TipoPeriodo FOREIGN KEY (TipoPeriodoProgramacionId)
        REFERENCES Programacion.TipoPeriodoProgramacion(TipoPeriodoProgramacionId),
    CONSTRAINT FK_CargaProgramacion_ProgramacionPeriodo FOREIGN KEY (ProgramacionPeriodoId)
        REFERENCES Programacion.ProgramacionPeriodo(ProgramacionPeriodoId),
    CONSTRAINT UQ_CargaProgramacionCodigo UNIQUE (CargaProgramacionCodigo),
    CONSTRAINT CK_CargaProgramacionMes    CHECK (CargaProgramacionMes BETWEEN 1 AND 12),
    CONSTRAINT CK_CargaProgramacionNumero CHECK (CargaProgramacionNumero IS NULL OR CargaProgramacionNumero BETWEEN 1 AND 2),
    CONSTRAINT CK_CargaProgramacionEstado CHECK (
        CargaProgramacionEstado IN (N'REGISTRADO', N'OBSERVADO', N'CONFORME', N'ANULADO'))
);
GO

/* ================================================================================
   11. ASISTENCIA  (incluye FALTAS y JUSTIFICACION - punto 6 del encargo)
   ================================================================================ */
/* (+) EstadoAsistenciaEsFalta: marca que estados constituyen inasistencia y por
   tanto son JUSTIFICABLES. Sin esta bandera, el modulo de justificacion tendria
   que comparar cadenas de texto. */
IF OBJECT_ID(N'Asistencia.EstadoAsistencia', N'U') IS NULL
CREATE TABLE Asistencia.EstadoAsistencia (
    EstadoAsistenciaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_EstadoAsistencia PRIMARY KEY,
    EstadoAsistenciaCodigo      NVARCHAR(30)  NOT NULL,
    EstadoAsistenciaNombre      NVARCHAR(100) NOT NULL,
    EstadoAsistenciaDescripcion NVARCHAR(250) NULL,
    EstadoAsistenciaEsFalta     BIT NOT NULL CONSTRAINT DF_EstadoAsistenciaEsFalta     DEFAULT (0),
    EstadoAsistenciaEsDescontable BIT NOT NULL CONSTRAINT DF_EstadoAsistenciaEsDescontable DEFAULT (0),
    EstadoAsistenciaEsLaborable BIT NOT NULL CONSTRAINT DF_EstadoAsistenciaEsLaborable DEFAULT (1),
    EstadoAsistenciaEstado      BIT NOT NULL CONSTRAINT DF_EstadoAsistenciaEstado      DEFAULT (1),
    CONSTRAINT UQ_EstadoAsistenciaCodigo UNIQUE (EstadoAsistenciaCodigo),
    CONSTRAINT UQ_EstadoAsistenciaNombre UNIQUE (EstadoAsistenciaNombre)
);
GO

/* ------------------------------------------------------------------------------
   CONCEPTO DE JUSTIFICACION (NUEVO) - catalogo de motivos de justificacion.
   Reemplaza el texto libre AvisoAusenciaMotivo del modelo anterior.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Asistencia.ConceptoJustificacion', N'U') IS NULL
CREATE TABLE Asistencia.ConceptoJustificacion (
    ConceptoJustificacionId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ConceptoJustificacion PRIMARY KEY,
    ConceptoJustificacionCodigo      NVARCHAR(30)  NOT NULL,
    ConceptoJustificacionNombre      NVARCHAR(150) NOT NULL,
    ConceptoJustificacionDescripcion NVARCHAR(300) NULL,
    ConceptoJustificacionRequiereDocumento BIT NOT NULL CONSTRAINT DF_ConceptoJustificacionRequiereDoc DEFAULT (1),
    ConceptoJustificacionEsRemunerado BIT NOT NULL CONSTRAINT DF_ConceptoJustificacionEsRemunerado DEFAULT (1),
    ConceptoJustificacionEstado      BIT NOT NULL CONSTRAINT DF_ConceptoJustificacionEstado DEFAULT (1),
    CONSTRAINT UQ_ConceptoJustificacionCodigo UNIQUE (ConceptoJustificacionCodigo),
    CONSTRAINT UQ_ConceptoJustificacionNombre UNIQUE (ConceptoJustificacionNombre)
);
GO

/* ------------------------------------------------------------------------------
   JUSTIFICACION DE FALTAS (NUEVA) - punto 6 del encargo
   ------------------------------------------------------------------------------
   ABSORBE la tabla Solicitudes.AvisoAusencia del modelo anterior, que tenia
   exactamente esta granularidad (vinculo + periodo + motivo + documento + estado)
   pero sin catalogo de conceptos, sin flujo de aprobacion y sin conexion con la
   asistencia diaria. Se reutiliza el concepto en lugar de crear una tabla aislada.

   La FALTA en si NO necesita tabla propia: ya existe como fila de
   Asistencia.AsistenciaDiaria con un EstadoAsistencia marcado EsFalta = 1.
   El enlace es Asistencia.AsistenciaDiaria.JustificacionFaltaId (FK nulable):
   una justificacion cubre N dias, cada dia admite a lo sumo una justificacion.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Asistencia.JustificacionFalta', N'U') IS NULL
CREATE TABLE Asistencia.JustificacionFalta (
    JustificacionFaltaId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_JustificacionFalta PRIMARY KEY,
    VinculoLaboralId                INT NOT NULL,
    ConceptoJustificacionId         INT NOT NULL,
    DocumentoSustentoId             BIGINT NULL,
    UsuarioRegistroId               INT NOT NULL,
    UsuarioResolucionId             INT NULL,
    JustificacionFaltaFechaInicio   DATE NOT NULL,
    JustificacionFaltaFechaFin      DATE NOT NULL,
    JustificacionFaltaDocumentoNumero NVARCHAR(60) NULL,
    JustificacionFaltaObservacion   NVARCHAR(1000) NULL,
    JustificacionFaltaMotivoRechazo NVARCHAR(500) NULL,
    JustificacionFaltaFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_JustificacionFaltaFechaRegistro DEFAULT (SYSDATETIME()),
    JustificacionFaltaFechaResolucion DATETIME2(0) NULL,
    JustificacionFaltaEstado        NVARCHAR(30) NOT NULL CONSTRAINT DF_JustificacionFaltaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_JustificacionFalta_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_JustificacionFalta_Concepto FOREIGN KEY (ConceptoJustificacionId)
        REFERENCES Asistencia.ConceptoJustificacion(ConceptoJustificacionId),
    CONSTRAINT FK_JustificacionFalta_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_JustificacionFalta_UsuarioRegistro FOREIGN KEY (UsuarioRegistroId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_JustificacionFalta_UsuarioResolucion FOREIGN KEY (UsuarioResolucionId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_JustificacionFaltaFechas CHECK (JustificacionFaltaFechaFin >= JustificacionFaltaFechaInicio),
    CONSTRAINT CK_JustificacionFaltaEstado CHECK (
        JustificacionFaltaEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Asistencia.CargaAsistenciaManual', N'U') IS NULL
CREATE TABLE Asistencia.CargaAsistenciaManual (
    CargaAsistenciaManualId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CargaAsistenciaManual PRIMARY KEY,
    UsuarioId                        INT NOT NULL,
    EessId                           INT NULL,
    DocumentoSustentoId              BIGINT NULL,
    CargaAsistenciaManualFecha       DATETIME2(0) NOT NULL CONSTRAINT DF_CargaAsistenciaManualFecha DEFAULT (SYSDATETIME()),
    CargaAsistenciaManualNombreArchivo NVARCHAR(255) NULL,
    CargaAsistenciaManualRegistros   INT NULL,
    CargaAsistenciaManualObservacion NVARCHAR(1000) NULL,
    CargaAsistenciaManualEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_CargaAsistenciaManualEstado DEFAULT (N'REGISTRADO'),
    CONSTRAINT FK_CargaAsistenciaManual_Usuario FOREIGN KEY (UsuarioId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_CargaAsistenciaManual_Eess FOREIGN KEY (EessId)
        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_CargaAsistenciaManual_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_CargaAsistenciaManualEstado CHECK (
        CargaAsistenciaManualEstado IN (N'REGISTRADO', N'PROCESADO', N'OBSERVADO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Asistencia.Marcacion', N'U') IS NULL
CREATE TABLE Asistencia.Marcacion (
    MarcacionId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Marcacion PRIMARY KEY,
    VinculoLaboralId         INT NOT NULL,
    MetodoMarcacionId        INT NOT NULL,
    DispositivoMarcacionId   INT NULL,
    PlantillaBiometricaId    BIGINT NULL,
    CargaAsistenciaManualId  BIGINT NULL,     -- trazabilidad si vino de un archivo
    MarcacionFechaHora       DATETIME2(0) NOT NULL,
    MarcacionTipo            NVARCHAR(30) NOT NULL,
    MarcacionGeolocalizacion NVARCHAR(100) NULL,
    MarcacionObservacion     NVARCHAR(500) NULL,
    MarcacionOrigen          NVARCHAR(50)  NULL,
    MarcacionEsValida        BIT NOT NULL CONSTRAINT DF_MarcacionEsValida DEFAULT (1),
    CONSTRAINT FK_Marcacion_VinculoLaboral  FOREIGN KEY (VinculoLaboralId)       REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_Marcacion_MetodoMarcacion FOREIGN KEY (MetodoMarcacionId)      REFERENCES Biometria.MetodoMarcacion(MetodoMarcacionId),
    CONSTRAINT FK_Marcacion_Dispositivo     FOREIGN KEY (DispositivoMarcacionId) REFERENCES Biometria.DispositivoMarcacion(DispositivoMarcacionId),
    CONSTRAINT FK_Marcacion_Plantilla       FOREIGN KEY (PlantillaBiometricaId)  REFERENCES Biometria.PlantillaBiometrica(PlantillaBiometricaId),
    CONSTRAINT FK_Marcacion_CargaManual     FOREIGN KEY (CargaAsistenciaManualId) REFERENCES Asistencia.CargaAsistenciaManual(CargaAsistenciaManualId),
    CONSTRAINT CK_MarcacionTipo CHECK (
        MarcacionTipo IN (N'ENTRADA', N'SALIDA', N'SALIDA_PAPELETA', N'RETORNO_PAPELETA',
                          N'SALIDA_REFRIGERIO', N'RETORNO_REFRIGERIO'))
);
GO

IF OBJECT_ID(N'Asistencia.AjusteMarcacion', N'U') IS NULL
CREATE TABLE Asistencia.AjusteMarcacion (
    AjusteMarcacionId         BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AjusteMarcacion PRIMARY KEY,
    MarcacionId               BIGINT NOT NULL,
    UsuarioId                 INT NOT NULL,
    DocumentoSustentoId       BIGINT NULL,
    AjusteMarcacionFechaHora  DATETIME2(0) NOT NULL CONSTRAINT DF_AjusteMarcacionFechaHora DEFAULT (SYSDATETIME()),
    AjusteMarcacionFechaHoraAnterior DATETIME2(0) NULL,
    AjusteMarcacionFechaHoraNueva    DATETIME2(0) NULL,
    AjusteMarcacionMotivo     NVARCHAR(1000) NOT NULL,
    AjusteMarcacionEstado     NVARCHAR(30) NOT NULL CONSTRAINT DF_AjusteMarcacionEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_AjusteMarcacion_Marcacion FOREIGN KEY (MarcacionId)         REFERENCES Asistencia.Marcacion(MarcacionId),
    CONSTRAINT FK_AjusteMarcacion_Usuario   FOREIGN KEY (UsuarioId)           REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_AjusteMarcacion_Documento FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_AjusteMarcacionEstado CHECK (
        AjusteMarcacionEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

/* ASISTENCIA DIARIA: el hecho consolidado del dia. Es tambien LA FALTA cuando su
   EstadoAsistencia tiene EsFalta = 1.
   (+) JustificacionFaltaId: enlaza la falta con su justificacion aprobada.
   La grano sigue siendo un registro por vinculo y fecha. */
IF OBJECT_ID(N'Asistencia.AsistenciaDiaria', N'U') IS NULL
CREATE TABLE Asistencia.AsistenciaDiaria (
    AsistenciaDiariaId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AsistenciaDiaria PRIMARY KEY,
    VinculoLaboralId                INT NOT NULL,
    TurnoProgramadoId               BIGINT NULL,     -- turno esperado ese dia
    EstadoAsistenciaId              INT NOT NULL,
    JustificacionFaltaId            BIGINT NULL,     -- <-- justificacion de la falta
    AsistenciaDiariaFecha           DATE NOT NULL,
    AsistenciaDiariaHoraEntrada     DATETIME2(0) NULL,
    AsistenciaDiariaHoraSalida      DATETIME2(0) NULL,
    AsistenciaDiariaMinutosTardanza INT NOT NULL CONSTRAINT DF_AsistenciaDiariaMinutosTardanza DEFAULT (0),
    AsistenciaDiariaMinutosFalta    INT NOT NULL CONSTRAINT DF_AsistenciaDiariaMinutosFalta    DEFAULT (0),
    AsistenciaDiariaMinutosExtra    INT NOT NULL CONSTRAINT DF_AsistenciaDiariaMinutosExtra    DEFAULT (0),
    AsistenciaDiariaMinutosTrabajados INT NOT NULL CONSTRAINT DF_AsistenciaDiariaMinutosTrabajados DEFAULT (0),
    AsistenciaDiariaObservacion     NVARCHAR(1000) NULL,
    AsistenciaDiariaFechaProceso    DATETIME2(0) NULL,
    CONSTRAINT FK_AsistenciaDiaria_VinculoLaboral  FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_AsistenciaDiaria_TurnoProgramado FOREIGN KEY (TurnoProgramadoId)   REFERENCES Programacion.TurnoProgramado(TurnoProgramadoId),
    CONSTRAINT FK_AsistenciaDiaria_Estado          FOREIGN KEY (EstadoAsistenciaId)  REFERENCES Asistencia.EstadoAsistencia(EstadoAsistenciaId),
    CONSTRAINT FK_AsistenciaDiaria_Justificacion   FOREIGN KEY (JustificacionFaltaId) REFERENCES Asistencia.JustificacionFalta(JustificacionFaltaId),
    CONSTRAINT UQ_AsistenciaDiaria UNIQUE (VinculoLaboralId, AsistenciaDiariaFecha),
    CONSTRAINT CK_AsistenciaDiariaMinutos CHECK (
        AsistenciaDiariaMinutosTardanza   >= 0 AND
        AsistenciaDiariaMinutosFalta      >= 0 AND
        AsistenciaDiariaMinutosExtra      >= 0 AND
        AsistenciaDiariaMinutosTrabajados >= 0)
);
GO

/* ================================================================================
   12. SOLICITUDES  (papeletas, licencias, descansos)
   ================================================================================ */
/* ------------------------------------------------------------------------------
   TIPO DE PAPELETA (NUEVO) - punto 7 del encargo
   ------------------------------------------------------------------------------
   Catalogo de PRIMER nivel: naturaleza del permiso (Comision de servicio, Permiso
   particular, Permiso oficial, Lactancia, Salud, Onomastico...). Determina si la
   papeleta es descontable, si requiere sustento y si afecta la jornada.

   ABSORBE Solicitudes.TipoPermiso, que era un catalogo paralelo del mismo dominio.

   Se ubica en el esquema Solicitudes y no en Personal (como sugeria el encargo)
   porque la papeleta es una SOLICITUD, y todo su ciclo de vida vive aqui junto a
   licencias y permisos. Personal agrupa la identidad laboral, no los tramites.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Solicitudes.TipoPapeleta', N'U') IS NULL
CREATE TABLE Solicitudes.TipoPapeleta (
    TipoPapeletaId              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoPapeleta PRIMARY KEY,
    TipoPapeletaCodigo          NVARCHAR(30)  NOT NULL,
    TipoPapeletaNombre          NVARCHAR(150) NOT NULL,
    TipoPapeletaDescripcion     NVARCHAR(300) NULL,
    TipoPapeletaEsDescontable   BIT NOT NULL CONSTRAINT DF_TipoPapeletaEsDescontable   DEFAULT (0),
    TipoPapeletaRequiereSustento BIT NOT NULL CONSTRAINT DF_TipoPapeletaRequiereSustento DEFAULT (0),
    TipoPapeletaAfectaJornada   BIT NOT NULL CONSTRAINT DF_TipoPapeletaAfectaJornada   DEFAULT (1),
    TipoPapeletaEsCompensable   BIT NOT NULL CONSTRAINT DF_TipoPapeletaEsCompensable   DEFAULT (0),
    TipoPapeletaEstado          BIT NOT NULL CONSTRAINT DF_TipoPapeletaEstado          DEFAULT (1),
    CONSTRAINT UQ_TipoPapeletaCodigo UNIQUE (TipoPapeletaCodigo),
    CONSTRAINT UQ_TipoPapeletaNombre UNIQUE (TipoPapeletaNombre)
);
GO

/* MOTIVO DE PAPELETA: catalogo de SEGUNDO nivel, ya existente en el modelo previo.
   Ahora cuelga de su tipo. El UNIQUE compuesto (MotivoPapeletaId, TipoPapeletaId)
   es artificial pero necesario: permite a Papeleta declarar una FK COMPUESTA que
   impide asociar un motivo con un tipo al que no pertenece. */
IF OBJECT_ID(N'Solicitudes.MotivoPapeleta', N'U') IS NULL
CREATE TABLE Solicitudes.MotivoPapeleta (
    MotivoPapeletaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_MotivoPapeleta PRIMARY KEY,
    TipoPapeletaId            INT NOT NULL,
    MotivoPapeletaCodigo      NVARCHAR(30)  NOT NULL,
    MotivoPapeletaNombre      NVARCHAR(150) NOT NULL,
    MotivoPapeletaDescripcion NVARCHAR(300) NULL,
    MotivoPapeletaEstado      BIT NOT NULL CONSTRAINT DF_MotivoPapeletaEstado DEFAULT (1),
    CONSTRAINT FK_MotivoPapeleta_TipoPapeleta FOREIGN KEY (TipoPapeletaId)
        REFERENCES Solicitudes.TipoPapeleta(TipoPapeletaId),
    CONSTRAINT UQ_MotivoPapeletaCodigo UNIQUE (MotivoPapeletaCodigo),
    CONSTRAINT UQ_MotivoPapeletaNombre UNIQUE (MotivoPapeletaNombre),
    CONSTRAINT UQ_MotivoPapeletaTipo   UNIQUE (MotivoPapeletaId, TipoPapeletaId)
);
GO

/* ------------------------------------------------------------------------------
   PAPELETA  (antes Solicitudes.PapeletaSalida)
   ------------------------------------------------------------------------------
   FUSIONA Solicitudes.PapeletaSalida + Solicitudes.PermisoHorario, que registraban
   el mismo hecho (ausentarse unas horas con autorizacion) con dos catalogos y dos
   tablas paralelas.

   Se renombra a "Papeleta" porque ya no cubre solo salidas: tambien comision de
   servicio, lactancia y permisos de dia completo.

   La FK COMPUESTA (MotivoPapeletaId, TipoPapeletaId) garantiza que el tipo sea
   consultable directamente desde la papeleta SIN riesgo de incoherencia con el
   motivo. Asi se cumple  TipoPapeleta -> Papeleta  sin romper la 3FN.
   ------------------------------------------------------------------------------ */
IF OBJECT_ID(N'Solicitudes.Papeleta', N'U') IS NULL
CREATE TABLE Solicitudes.Papeleta (
    PapeletaId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Papeleta PRIMARY KEY,
    VinculoLaboralId        INT NOT NULL,
    TipoPapeletaId          INT NOT NULL,
    MotivoPapeletaId        INT NULL,            -- detalle opcional dentro del tipo
    DocumentoSustentoId     BIGINT NULL,
    UsuarioRegistroId       INT NULL,
    UsuarioAutorizacionId   INT NULL,
    PapeletaNumero          NVARCHAR(50) NULL,
    PapeletaFecha           DATE NOT NULL,
    PapeletaHoraSalida      TIME(0) NULL,
    PapeletaHoraRetorno     TIME(0) NULL,
    PapeletaEsDiaCompleto   BIT NOT NULL CONSTRAINT DF_PapeletaEsDiaCompleto DEFAULT (0),
    PapeletaMinutosUtilizados INT NULL,
    PapeletaMotivo          NVARCHAR(1000) NULL, -- detalle libre del caso concreto
    PapeletaObservacion     NVARCHAR(1000) NULL,
    PapeletaFechaRegistro   DATETIME2(0) NOT NULL CONSTRAINT DF_PapeletaFechaRegistro DEFAULT (SYSDATETIME()),
    PapeletaFechaResolucion DATETIME2(0) NULL,
    PapeletaEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_PapeletaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_Papeleta_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_Papeleta_TipoPapeleta FOREIGN KEY (TipoPapeletaId)
        REFERENCES Solicitudes.TipoPapeleta(TipoPapeletaId),
    /* FK compuesta: el motivo debe pertenecer al tipo declarado */
    CONSTRAINT FK_Papeleta_MotivoTipo FOREIGN KEY (MotivoPapeletaId, TipoPapeletaId)
        REFERENCES Solicitudes.MotivoPapeleta(MotivoPapeletaId, TipoPapeletaId),
    CONSTRAINT FK_Papeleta_Documento FOREIGN KEY (DocumentoSustentoId)
        REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_Papeleta_UsuarioRegistro FOREIGN KEY (UsuarioRegistroId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_Papeleta_UsuarioAutorizacion FOREIGN KEY (UsuarioAutorizacionId)
        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_PapeletaHoras CHECK (
        PapeletaHoraRetorno IS NULL OR PapeletaHoraSalida IS NULL OR
        PapeletaHoraRetorno >= PapeletaHoraSalida),
    CONSTRAINT CK_PapeletaMinutos CHECK (PapeletaMinutosUtilizados IS NULL OR PapeletaMinutosUtilizados >= 0),
    CONSTRAINT CK_PapeletaEstado CHECK (
        PapeletaEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Solicitudes.TipoLicencia', N'U') IS NULL
CREATE TABLE Solicitudes.TipoLicencia (
    TipoLicenciaId            INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoLicencia PRIMARY KEY,
    TipoLicenciaCodigo        NVARCHAR(30)  NOT NULL,
    TipoLicenciaNombre        NVARCHAR(150) NOT NULL,
    TipoLicenciaDescripcion   NVARCHAR(300) NULL,
    TipoLicenciaConGoce       BIT NOT NULL CONSTRAINT DF_TipoLicenciaConGoce       DEFAULT (1),
    TipoLicenciaMaximoDias    SMALLINT NULL,
    TipoLicenciaBaseLegal     NVARCHAR(200) NULL,
    TipoLicenciaEstado        BIT NOT NULL CONSTRAINT DF_TipoLicenciaEstado        DEFAULT (1),
    CONSTRAINT UQ_TipoLicenciaCodigo UNIQUE (TipoLicenciaCodigo),
    CONSTRAINT UQ_TipoLicenciaNombre UNIQUE (TipoLicenciaNombre)
);
GO

IF OBJECT_ID(N'Solicitudes.Licencia', N'U') IS NULL
CREATE TABLE Solicitudes.Licencia (
    LicenciaId            BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Licencia PRIMARY KEY,
    VinculoLaboralId      INT NOT NULL,
    TipoLicenciaId        INT NOT NULL,
    DocumentoSustentoId   BIGINT NULL,
    UsuarioRegistroId     INT NULL,
    LicenciaNumeroResolucion NVARCHAR(60) NULL,
    LicenciaFechaInicio   DATE NOT NULL,
    LicenciaFechaFin      DATE NOT NULL,
    LicenciaMotivo        NVARCHAR(1000) NULL,
    LicenciaFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_LicenciaFechaRegistro DEFAULT (SYSDATETIME()),
    LicenciaEstado        NVARCHAR(30) NOT NULL CONSTRAINT DF_LicenciaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_Licencia_VinculoLaboral FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_Licencia_TipoLicencia   FOREIGN KEY (TipoLicenciaId)      REFERENCES Solicitudes.TipoLicencia(TipoLicenciaId),
    CONSTRAINT FK_Licencia_Documento      FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_Licencia_UsuarioRegistro FOREIGN KEY (UsuarioRegistroId)  REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_LicenciaFechas CHECK (LicenciaFechaFin >= LicenciaFechaInicio),
    CONSTRAINT CK_LicenciaEstado CHECK (
        LicenciaEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

/* DESCANSO MEDICO: se CONSERVA como entidad propia y no se funde con Licencia.
   Motivo: el descanso medico nace de un CITT/certificado, alimenta el subsidio de
   EsSalud y es el hecho que dispara la constatacion domiciliaria. La licencia, en
   cambio, nace de una resolucion administrativa. Son dos instrumentos distintos
   con distinto sustento y distinto efecto economico. La frontera queda documentada. */
IF OBJECT_ID(N'Solicitudes.DescansoMedico', N'U') IS NULL
CREATE TABLE Solicitudes.DescansoMedico (
    DescansoMedicoId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DescansoMedico PRIMARY KEY,
    VinculoLaboralId          INT NOT NULL,
    DocumentoSustentoId       BIGINT NULL,
    DescansoMedicoNumeroCitt  NVARCHAR(60) NULL,
    DescansoMedicoDiagnostico NVARCHAR(300) NULL,
    DescansoMedicoFechaInicio DATE NOT NULL,
    DescansoMedicoFechaFin    DATE NOT NULL,
    DescansoMedicoObservacion NVARCHAR(1000) NULL,
    DescansoMedicoFechaRegistro DATETIME2(0) NOT NULL CONSTRAINT DF_DescansoMedicoFechaRegistro DEFAULT (SYSDATETIME()),
    DescansoMedicoEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_DescansoMedicoEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_DescansoMedico_VinculoLaboral FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_DescansoMedico_Documento      FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_DescansoMedicoFechas CHECK (DescansoMedicoFechaFin >= DescansoMedicoFechaInicio),
    CONSTRAINT CK_DescansoMedicoEstado CHECK (
        DescansoMedicoEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

/* (+) DescansoMedicoId: RELACION FALTANTE en el modelo anterior. La constatacion
   domiciliaria verifica un descanso medico concreto; antes solo se sabia el
   trabajador y la fecha. */
IF OBJECT_ID(N'Solicitudes.ConstatacionDomiciliaria', N'U') IS NULL
CREATE TABLE Solicitudes.ConstatacionDomiciliaria (
    ConstatacionDomiciliariaId        BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ConstatacionDomiciliaria PRIMARY KEY,
    VinculoLaboralId                  INT NOT NULL,
    DescansoMedicoId                  BIGINT NULL,
    DocumentoSustentoId               BIGINT NULL,
    UsuarioRegistroId                 INT NULL,
    ConstatacionDomiciliariaFecha     DATE NOT NULL,
    ConstatacionDomiciliariaDireccion NVARCHAR(300) NULL,
    ConstatacionDomiciliariaResultado NVARCHAR(500) NULL,
    ConstatacionDomiciliariaEstado    NVARCHAR(30) NOT NULL CONSTRAINT DF_ConstatacionDomiciliariaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_ConstatacionDomiciliaria_VinculoLaboral FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_ConstatacionDomiciliaria_DescansoMedico FOREIGN KEY (DescansoMedicoId)    REFERENCES Solicitudes.DescansoMedico(DescansoMedicoId),
    CONSTRAINT FK_ConstatacionDomiciliaria_Documento      FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT FK_ConstatacionDomiciliaria_Usuario        FOREIGN KEY (UsuarioRegistroId)   REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_ConstatacionDomiciliariaEstado CHECK (
        ConstatacionDomiciliariaEstado IN (N'PENDIENTE', N'CONFORME', N'NO_CONFORME', N'ANULADO'))
);
GO

/* (+) EessId: una ocurrencia de porteria sucede en un EESS concreto.
   Antes no habia forma de saber donde. */
IF OBJECT_ID(N'Solicitudes.OcurrenciaPorteria', N'U') IS NULL
CREATE TABLE Solicitudes.OcurrenciaPorteria (
    OcurrenciaPorteriaId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_OcurrenciaPorteria PRIMARY KEY,
    EessId                        INT NOT NULL,
    VinculoLaboralId              INT NULL,
    UsuarioId                     INT NULL,
    OcurrenciaPorteriaFechaHora   DATETIME2(0) NOT NULL CONSTRAINT DF_OcurrenciaPorteriaFechaHora DEFAULT (SYSDATETIME()),
    OcurrenciaPorteriaTipo        NVARCHAR(50) NOT NULL,
    OcurrenciaPorteriaDescripcion NVARCHAR(1000) NULL,
    OcurrenciaPorteriaEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_OcurrenciaPorteriaEstado DEFAULT (N'REGISTRADO'),
    CONSTRAINT FK_OcurrenciaPorteria_Eess FOREIGN KEY (EessId) REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_OcurrenciaPorteria_VinculoLaboral FOREIGN KEY (VinculoLaboralId) REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_OcurrenciaPorteria_Usuario        FOREIGN KEY (UsuarioId)        REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_OcurrenciaPorteriaEstado CHECK (
        OcurrenciaPorteriaEstado IN (N'REGISTRADO', N'ATENDIDO', N'ANULADO'))
);
GO

/* ================================================================================
   13. VACACIONES
   ================================================================================ */
IF OBJECT_ID(N'Vacaciones.PeriodoVacacional', N'U') IS NULL
CREATE TABLE Vacaciones.PeriodoVacacional (
    PeriodoVacacionalId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PeriodoVacacional PRIMARY KEY,
    VinculoLaboralId                 INT NOT NULL,
    PeriodoVacacionalAnio            SMALLINT NOT NULL,
    PeriodoVacacionalFechaInicio     DATE NOT NULL,
    PeriodoVacacionalFechaFin        DATE NOT NULL,
    PeriodoVacacionalDiasGanados     DECIMAL(6,2) NOT NULL CONSTRAINT DF_PeriodoVacacionalDiasGanados DEFAULT (30),
    PeriodoVacacionalDiasDisponibles DECIMAL(6,2) NOT NULL,
    PeriodoVacacionalEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_PeriodoVacacionalEstado DEFAULT (N'ABIERTO'),
    CONSTRAINT FK_PeriodoVacacional_VinculoLaboral FOREIGN KEY (VinculoLaboralId)
        REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT UQ_PeriodoVacacionalAnio UNIQUE (VinculoLaboralId, PeriodoVacacionalAnio),
    CONSTRAINT CK_PeriodoVacacionalFechas CHECK (PeriodoVacacionalFechaFin >= PeriodoVacacionalFechaInicio),
    CONSTRAINT CK_PeriodoVacacionalDias   CHECK (PeriodoVacacionalDiasDisponibles >= 0 AND PeriodoVacacionalDiasGanados >= 0),
    CONSTRAINT CK_PeriodoVacacionalEstado CHECK (PeriodoVacacionalEstado IN (N'ABIERTO', N'CERRADO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Vacaciones.RolVacacional', N'U') IS NULL
CREATE TABLE Vacaciones.RolVacacional (
    RolVacacionalId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RolVacacional PRIMARY KEY,
    PeriodoVacacionalId          BIGINT NOT NULL,
    RolVacacionalFechaProgramada DATE NOT NULL,
    RolVacacionalFechaFinProgramada DATE NULL,
    RolVacacionalDias            DECIMAL(6,2) NOT NULL,
    RolVacacionalEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_RolVacacionalEstado DEFAULT (N'PROGRAMADO'),
    CONSTRAINT FK_RolVacacional_PeriodoVacacional FOREIGN KEY (PeriodoVacacionalId)
        REFERENCES Vacaciones.PeriodoVacacional(PeriodoVacacionalId),
    CONSTRAINT CK_RolVacacionalDias   CHECK (RolVacacionalDias > 0),
    CONSTRAINT CK_RolVacacionalEstado CHECK (RolVacacionalEstado IN (N'PROGRAMADO', N'GOZADO', N'REPROGRAMADO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Vacaciones.GoceVacacional', N'U') IS NULL
CREATE TABLE Vacaciones.GoceVacacional (
    GoceVacacionalId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_GoceVacacional PRIMARY KEY,
    RolVacacionalId           BIGINT NOT NULL,
    DocumentoSustentoId       BIGINT NULL,
    GoceVacacionalFechaInicio DATE NOT NULL,
    GoceVacacionalFechaFin    DATE NOT NULL,
    GoceVacacionalDias        DECIMAL(6,2) NOT NULL,
    GoceVacacionalEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_GoceVacacionalEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_GoceVacacional_RolVacacional FOREIGN KEY (RolVacacionalId)    REFERENCES Vacaciones.RolVacacional(RolVacacionalId),
    CONSTRAINT FK_GoceVacacional_Documento     FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_GoceVacacionalFechas CHECK (GoceVacacionalFechaFin >= GoceVacacionalFechaInicio),
    CONSTRAINT CK_GoceVacacionalDias   CHECK (GoceVacacionalDias > 0),
    CONSTRAINT CK_GoceVacacionalEstado CHECK (GoceVacacionalEstado IN (N'PENDIENTE', N'APROBADO', N'RECHAZADO', N'ANULADO'))
);
GO

/* ================================================================================
   14. COMPENSACIONES
   ================================================================================ */
IF OBJECT_ID(N'Compensaciones.TipoCompensacion', N'U') IS NULL
CREATE TABLE Compensaciones.TipoCompensacion (
    TipoCompensacionId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoCompensacion PRIMARY KEY,
    TipoCompensacionCodigo      NVARCHAR(30)  NOT NULL,
    TipoCompensacionNombre      NVARCHAR(150) NOT NULL,
    TipoCompensacionDescripcion NVARCHAR(300) NULL,
    TipoCompensacionEstado      BIT NOT NULL CONSTRAINT DF_TipoCompensacionEstado DEFAULT (1),
    CONSTRAINT UQ_TipoCompensacionCodigo UNIQUE (TipoCompensacionCodigo),
    CONSTRAINT UQ_TipoCompensacionNombre UNIQUE (TipoCompensacionNombre)
);
GO

IF OBJECT_ID(N'Compensaciones.ConceptoDescuento', N'U') IS NULL
CREATE TABLE Compensaciones.ConceptoDescuento (
    ConceptoDescuentoId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ConceptoDescuento PRIMARY KEY,
    ConceptoDescuentoCodigo      NVARCHAR(50)  NOT NULL,
    ConceptoDescuentoNombre      NVARCHAR(150) NOT NULL,
    ConceptoDescuentoDescripcion NVARCHAR(300) NULL,
    ConceptoDescuentoEstado      BIT NOT NULL CONSTRAINT DF_ConceptoDescuentoEstado DEFAULT (1),
    CONSTRAINT UQ_ConceptoDescuentoCodigo UNIQUE (ConceptoDescuentoCodigo),
    CONSTRAINT UQ_ConceptoDescuentoNombre UNIQUE (ConceptoDescuentoNombre)
);
GO

/* CompensacionHorariaOrigenId existia como columna HUERFANA (sin FK) en el modelo
   anterior. SE ELIMINA: no se usan autorreferencias en este modelo. El origen de
   las horas se conoce por TipoCompensacionId y por AsistenciaDiariaId. */
IF OBJECT_ID(N'Compensaciones.CompensacionHoraria', N'U') IS NULL
CREATE TABLE Compensaciones.CompensacionHoraria (
    CompensacionHorariaId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_CompensacionHoraria PRIMARY KEY,
    VinculoLaboralId                   INT NOT NULL,
    TipoCompensacionId                 INT NOT NULL,
    AsistenciaDiariaId                 BIGINT NULL,
    CompensacionHorariaAutorizadoPor   INT NULL,
    CompensacionHorariaFechaGeneracion DATETIME2(0) NOT NULL CONSTRAINT DF_CompensacionHorariaFechaGeneracion DEFAULT (SYSDATETIME()),
    CompensacionHorariaHorasGeneradas  DECIMAL(7,2) NOT NULL,
    CompensacionHorariaHorasDevueltas  DECIMAL(7,2) NOT NULL CONSTRAINT DF_CompensacionHorariaHorasDevueltas DEFAULT (0),
    CompensacionHorariaFechaLimite     DATE NULL,
    CompensacionHorariaAutorizadoPreviamente BIT NOT NULL CONSTRAINT DF_CompensacionHorariaAutorizadoPreviamente DEFAULT (0),
    CompensacionHorariaObservacion     NVARCHAR(500) NULL,
    CompensacionHorariaEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_CompensacionHorariaEstado DEFAULT (N'PENDIENTE'),
    CONSTRAINT FK_CompensacionHoraria_VinculoLaboral   FOREIGN KEY (VinculoLaboralId)   REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_CompensacionHoraria_TipoCompensacion FOREIGN KEY (TipoCompensacionId) REFERENCES Compensaciones.TipoCompensacion(TipoCompensacionId),
    CONSTRAINT FK_CompensacionHoraria_AsistenciaDiaria FOREIGN KEY (AsistenciaDiariaId) REFERENCES Asistencia.AsistenciaDiaria(AsistenciaDiariaId),
    CONSTRAINT FK_CompensacionHoraria_AutorizadoPor    FOREIGN KEY (CompensacionHorariaAutorizadoPor) REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_CompensacionHorariaHoras CHECK (
        CompensacionHorariaHorasGeneradas >= 0 AND
        CompensacionHorariaHorasDevueltas >= 0 AND
        CompensacionHorariaHorasDevueltas <= CompensacionHorariaHorasGeneradas),
    CONSTRAINT CK_CompensacionHorariaEstado CHECK (
        CompensacionHorariaEstado IN (N'PENDIENTE', N'APROBADO', N'CONSUMIDO', N'VENCIDO', N'ANULADO'))
);
GO

/* ================================================================================
   15. CONSOLIDACION
   ================================================================================ */
IF OBJECT_ID(N'Consolidacion.PeriodoAsistencia', N'U') IS NULL
CREATE TABLE Consolidacion.PeriodoAsistencia (
    PeriodoAsistenciaId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_PeriodoAsistencia PRIMARY KEY,
    PeriodoAsistenciaAnio        SMALLINT NOT NULL,
    PeriodoAsistenciaMes         TINYINT  NOT NULL,
    PeriodoAsistenciaFechaInicio DATE NOT NULL,
    PeriodoAsistenciaFechaFin    DATE NOT NULL,
    PeriodoAsistenciaFechaCierre DATETIME2(0) NULL,
    PeriodoAsistenciaEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_PeriodoAsistenciaEstado DEFAULT (N'ABIERTO'),
    CONSTRAINT UQ_PeriodoAsistenciaPeriodo UNIQUE (PeriodoAsistenciaAnio, PeriodoAsistenciaMes),
    CONSTRAINT CK_PeriodoAsistenciaMes    CHECK (PeriodoAsistenciaMes BETWEEN 1 AND 12),
    CONSTRAINT CK_PeriodoAsistenciaFechas CHECK (PeriodoAsistenciaFechaFin >= PeriodoAsistenciaFechaInicio),
    CONSTRAINT CK_PeriodoAsistenciaEstado CHECK (PeriodoAsistenciaEstado IN (N'ABIERTO', N'EN_PROCESO', N'CERRADO'))
);
GO

IF OBJECT_ID(N'Consolidacion.ConsolidadoAsistencia', N'U') IS NULL
CREATE TABLE Consolidacion.ConsolidadoAsistencia (
    ConsolidadoAsistenciaId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ConsolidadoAsistencia PRIMARY KEY,
    PeriodoAsistenciaId                  BIGINT NOT NULL,
    VinculoLaboralId                     INT NOT NULL,
    ConsolidadoAsistenciaDiasTrabajados  DECIMAL(8,2) NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaDiasTrabajados DEFAULT (0),
    ConsolidadoAsistenciaDiasFalta       DECIMAL(8,2) NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaDiasFalta      DEFAULT (0),
    ConsolidadoAsistenciaDiasFaltaJustificada DECIMAL(8,2) NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaDiasFaltaJust DEFAULT (0),
    ConsolidadoAsistenciaMinutosTardanza INT NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaMinutosTardanza DEFAULT (0),
    ConsolidadoAsistenciaMinutosExtra    INT NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaMinutosExtra    DEFAULT (0),
    ConsolidadoAsistenciaFechaGeneracion DATETIME2(0) NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaFechaGeneracion DEFAULT (SYSDATETIME()),
    ConsolidadoAsistenciaEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_ConsolidadoAsistenciaEstado DEFAULT (N'GENERADO'),
    CONSTRAINT FK_ConsolidadoAsistencia_Periodo        FOREIGN KEY (PeriodoAsistenciaId) REFERENCES Consolidacion.PeriodoAsistencia(PeriodoAsistenciaId),
    CONSTRAINT FK_ConsolidadoAsistencia_VinculoLaboral FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT UQ_ConsolidadoAsistencia UNIQUE (PeriodoAsistenciaId, VinculoLaboralId),
    CONSTRAINT CK_ConsolidadoAsistenciaEstado CHECK (
        ConsolidadoAsistenciaEstado IN (N'GENERADO', N'OBSERVADO', N'CONFORME', N'CERRADO'))
);
GO

/* DetalleConsolidado SE CONSERVA pese a replicar datos de AsistenciaDiaria.
   Justificacion: es una FOTO CONGELADA al momento del cierre. Si despues del
   cierre se corrige una asistencia diaria, el consolidado ya liquidado no debe
   alterarse. Es redundancia deliberada con finalidad de trazabilidad contable,
   no una violacion de normalizacion por descuido. */
IF OBJECT_ID(N'Consolidacion.DetalleConsolidado', N'U') IS NULL
CREATE TABLE Consolidacion.DetalleConsolidado (
    DetalleConsolidadoId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DetalleConsolidado PRIMARY KEY,
    ConsolidadoAsistenciaId           BIGINT NOT NULL,
    AsistenciaDiariaId                BIGINT NULL,
    DetalleConsolidadoFecha           DATE NOT NULL,
    DetalleConsolidadoEstado          NVARCHAR(50) NOT NULL,
    DetalleConsolidadoMinutosTardanza INT NOT NULL CONSTRAINT DF_DetalleConsolidadoMinutosTardanza DEFAULT (0),
    DetalleConsolidadoMinutosExtra    INT NOT NULL CONSTRAINT DF_DetalleConsolidadoMinutosExtra    DEFAULT (0),
    DetalleConsolidadoEsJustificada   BIT NOT NULL CONSTRAINT DF_DetalleConsolidadoEsJustificada  DEFAULT (0),
    CONSTRAINT FK_DetalleConsolidado_Consolidado FOREIGN KEY (ConsolidadoAsistenciaId) REFERENCES Consolidacion.ConsolidadoAsistencia(ConsolidadoAsistenciaId),
    CONSTRAINT FK_DetalleConsolidado_Asistencia  FOREIGN KEY (AsistenciaDiariaId)      REFERENCES Asistencia.AsistenciaDiaria(AsistenciaDiariaId),
    CONSTRAINT UQ_DetalleConsolidado UNIQUE (ConsolidadoAsistenciaId, DetalleConsolidadoFecha)
);
GO

/* ================================================================================
   16. LIQUIDACION
   --------------------------------------------------------------------------------
   (-) VinculoLaboralId: eliminado. Era una DEPENDENCIA TRANSITIVA: el consolidado
       ya determina el vinculo. Mantener ambos permitia una liquidacion cuyo vinculo
       no coincidiera con el de su consolidado.
   ================================================================================ */
IF OBJECT_ID(N'Compensaciones.LiquidacionDescuento', N'U') IS NULL
CREATE TABLE Compensaciones.LiquidacionDescuento (
    LiquidacionDescuentoId              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_LiquidacionDescuento PRIMARY KEY,
    ConsolidadoAsistenciaId             BIGINT NOT NULL,
    LiquidacionDescuentoFechaGeneracion DATETIME2(0) NOT NULL CONSTRAINT DF_LiquidacionDescuentoFechaGeneracion DEFAULT (SYSDATETIME()),
    LiquidacionDescuentoImporteTotal    DECIMAL(12,2) NOT NULL CONSTRAINT DF_LiquidacionDescuentoImporteTotal DEFAULT (0),
    LiquidacionDescuentoEstado          NVARCHAR(30) NOT NULL CONSTRAINT DF_LiquidacionDescuentoEstado DEFAULT (N'GENERADO'),
    CONSTRAINT FK_LiquidacionDescuento_Consolidado FOREIGN KEY (ConsolidadoAsistenciaId)
        REFERENCES Consolidacion.ConsolidadoAsistencia(ConsolidadoAsistenciaId),
    CONSTRAINT UQ_LiquidacionDescuento UNIQUE (ConsolidadoAsistenciaId),
    CONSTRAINT CK_LiquidacionDescuentoEstado CHECK (
        LiquidacionDescuentoEstado IN (N'GENERADO', N'APROBADO', N'REMITIDO', N'ANULADO'))
);
GO

IF OBJECT_ID(N'Compensaciones.DetalleLiquidacion', N'U') IS NULL
CREATE TABLE Compensaciones.DetalleLiquidacion (
    DetalleLiquidacionId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DetalleLiquidacion PRIMARY KEY,
    LiquidacionDescuentoId        BIGINT NOT NULL,
    ConceptoDescuentoId           INT NOT NULL,
    DetalleLiquidacionCantidad    DECIMAL(10,2) NOT NULL CONSTRAINT DF_DetalleLiquidacionCantidad DEFAULT (0),
    DetalleLiquidacionImporte     DECIMAL(12,2) NOT NULL CONSTRAINT DF_DetalleLiquidacionImporte  DEFAULT (0),
    DetalleLiquidacionObservacion NVARCHAR(500) NULL,
    CONSTRAINT FK_DetalleLiquidacion_Liquidacion FOREIGN KEY (LiquidacionDescuentoId) REFERENCES Compensaciones.LiquidacionDescuento(LiquidacionDescuentoId),
    CONSTRAINT FK_DetalleLiquidacion_Concepto    FOREIGN KEY (ConceptoDescuentoId)    REFERENCES Compensaciones.ConceptoDescuento(ConceptoDescuentoId),
    CONSTRAINT UQ_DetalleLiquidacion UNIQUE (LiquidacionDescuentoId, ConceptoDescuentoId),
    CONSTRAINT CK_DetalleLiquidacionImporte CHECK (DetalleLiquidacionImporte >= 0)
);
GO

/* ================================================================================
   17. DISCIPLINA
   --------------------------------------------------------------------------------
   RENOMBRADO: Disciplina.TipoFalta  ->  Disciplina.TipoFaltaDisciplinaria
   Motivo: colisionaba conceptualmente con "falta" = inasistencia. Son cosas
   distintas: una es una infraccion sancionable via PAD (Ley 30057), la otra es un
   dia no laborado. El nombre anterior inducia a error a cualquier desarrollador.
   ================================================================================ */
IF OBJECT_ID(N'Disciplina.TipoFaltaDisciplinaria', N'U') IS NULL
CREATE TABLE Disciplina.TipoFaltaDisciplinaria (
    TipoFaltaDisciplinariaId          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TipoFaltaDisciplinaria PRIMARY KEY,
    TipoFaltaDisciplinariaCodigo      NVARCHAR(50)  NOT NULL,
    TipoFaltaDisciplinariaNombre      NVARCHAR(150) NOT NULL,
    TipoFaltaDisciplinariaGravedad    NVARCHAR(30)  NULL,
    TipoFaltaDisciplinariaBaseLegal   NVARCHAR(200) NULL,
    TipoFaltaDisciplinariaDescripcion NVARCHAR(300) NULL,
    TipoFaltaDisciplinariaEstado      BIT NOT NULL CONSTRAINT DF_TipoFaltaDisciplinariaEstado DEFAULT (1),
    CONSTRAINT UQ_TipoFaltaDisciplinariaCodigo UNIQUE (TipoFaltaDisciplinariaCodigo),
    CONSTRAINT UQ_TipoFaltaDisciplinariaNombre UNIQUE (TipoFaltaDisciplinariaNombre),
    CONSTRAINT CK_TipoFaltaDisciplinariaGravedad CHECK (
        TipoFaltaDisciplinariaGravedad IS NULL OR
        TipoFaltaDisciplinariaGravedad IN (N'LEVE', N'GRAVE', N'MUY_GRAVE'))
);
GO

IF OBJECT_ID(N'Disciplina.ExpedientePad', N'U') IS NULL
CREATE TABLE Disciplina.ExpedientePad (
    ExpedientePadId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_ExpedientePad PRIMARY KEY,
    VinculoLaboralId         INT NOT NULL,
    TipoFaltaDisciplinariaId INT NOT NULL,
    DocumentoSustentoId      BIGINT NULL,
    ExpedientePadNumero      NVARCHAR(50) NULL,
    ExpedientePadFechaInicio DATE NOT NULL,
    ExpedientePadFechaFin    DATE NULL,
    ExpedientePadDescripcion NVARCHAR(1500) NULL,
    ExpedientePadSancion     NVARCHAR(300) NULL,
    ExpedientePadEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_ExpedientePadEstado DEFAULT (N'INICIADO'),
    CONSTRAINT FK_ExpedientePad_VinculoLaboral FOREIGN KEY (VinculoLaboralId)         REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_ExpedientePad_TipoFalta      FOREIGN KEY (TipoFaltaDisciplinariaId) REFERENCES Disciplina.TipoFaltaDisciplinaria(TipoFaltaDisciplinariaId),
    CONSTRAINT FK_ExpedientePad_Documento      FOREIGN KEY (DocumentoSustentoId)      REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_ExpedientePadFechas CHECK (ExpedientePadFechaFin IS NULL OR ExpedientePadFechaFin >= ExpedientePadFechaInicio),
    CONSTRAINT CK_ExpedientePadEstado CHECK (
        ExpedientePadEstado IN (N'INICIADO', N'EN_PROCESO', N'RESUELTO', N'ARCHIVADO', N'ANULADO'))
);
GO

/* (+) EessId: una supervision inopinada se realiza EN un EESS. */
IF OBJECT_ID(N'Disciplina.SupervisionInopinada', N'U') IS NULL
CREATE TABLE Disciplina.SupervisionInopinada (
    SupervisionInopinadaId          BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_SupervisionInopinada PRIMARY KEY,
    EessId                          INT NOT NULL,
    VinculoLaboralId                INT NULL,
    UsuarioId                       INT NOT NULL,
    DocumentoSustentoId             BIGINT NULL,
    SupervisionInopinadaFechaHora   DATETIME2(0) NOT NULL CONSTRAINT DF_SupervisionInopinadaFechaHora DEFAULT (SYSDATETIME()),
    SupervisionInopinadaResultado   NVARCHAR(500)  NULL,
    SupervisionInopinadaObservacion NVARCHAR(1000) NULL,
    SupervisionInopinadaEstado      NVARCHAR(30) NOT NULL CONSTRAINT DF_SupervisionInopinadaEstado DEFAULT (N'REGISTRADO'),
    CONSTRAINT FK_SupervisionInopinada_Eess FOREIGN KEY (EessId)                        REFERENCES Organizacion.EstablecimientoSalud(EessId),
    CONSTRAINT FK_SupervisionInopinada_VinculoLaboral FOREIGN KEY (VinculoLaboralId)    REFERENCES Personal.VinculoLaboral(VinculoLaboralId),
    CONSTRAINT FK_SupervisionInopinada_Usuario        FOREIGN KEY (UsuarioId)           REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT FK_SupervisionInopinada_Documento      FOREIGN KEY (DocumentoSustentoId) REFERENCES Soporte.DocumentoSustento(DocumentoSustentoId),
    CONSTRAINT CK_SupervisionInopinadaEstado CHECK (
        SupervisionInopinadaEstado IN (N'REGISTRADO', N'CONFORME', N'OBSERVADO', N'ANULADO'))
);
GO

/* ================================================================================
   18. AUDITORIA
   ================================================================================ */
IF OBJECT_ID(N'Seguridad.Auditoria', N'U') IS NULL
CREATE TABLE Seguridad.Auditoria (
    AuditoriaId             BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Auditoria PRIMARY KEY,
    UsuarioId               INT NULL,
    AuditoriaFechaHora      DATETIME2(0) NOT NULL CONSTRAINT DF_AuditoriaFechaHora DEFAULT (SYSDATETIME()),
    AuditoriaEsquema        NVARCHAR(128) NOT NULL,
    AuditoriaTabla          NVARCHAR(128) NOT NULL,
    AuditoriaOperacion      NVARCHAR(20)  NOT NULL,
    AuditoriaRegistroId     NVARCHAR(100) NULL,
    AuditoriaDatosAnteriores NVARCHAR(MAX) NULL,
    AuditoriaDatosNuevos    NVARCHAR(MAX) NULL,
    AuditoriaDireccionIp    NVARCHAR(45)  NULL,
    CONSTRAINT FK_Auditoria_Usuario FOREIGN KEY (UsuarioId) REFERENCES Seguridad.Usuario(UsuarioId),
    CONSTRAINT CK_AuditoriaOperacion CHECK (AuditoriaOperacion IN (N'INSERT', N'UPDATE', N'DELETE'))
);
GO

/* ================================================================================
   19. INDICES
   ================================================================================ */

/* ---- 19.1 INDICES UNICOS FILTRADOS -------------------------------------------
   SQL Server solo admite UN valor NULL por restriccion UNIQUE. El modelo anterior
   usaba UNIQUE sobre columnas nulables (CargoCodigo, VinculoLaboralCodigo,
   DocumentoSustentoHash, ExpedientePadNumero), lo que impedia tener mas de una
   fila sin codigo. Se reemplazan por indices unicos filtrados.
   ------------------------------------------------------------------------------ */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_Eess_Renipres' AND object_id=OBJECT_ID(N'Organizacion.EstablecimientoSalud'))
    CREATE UNIQUE INDEX UX_Eess_Renipres ON Organizacion.EstablecimientoSalud(EessCodigoRenipres)
    WHERE EessCodigoRenipres IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_Cargo_Codigo' AND object_id=OBJECT_ID(N'Personal.Cargo'))
    CREATE UNIQUE INDEX UX_Cargo_Codigo ON Personal.Cargo(CargoCodigo)
    WHERE CargoCodigo IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_VinculoLaboral_Codigo' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE UNIQUE INDEX UX_VinculoLaboral_Codigo ON Personal.VinculoLaboral(VinculoLaboralCodigo)
    WHERE VinculoLaboralCodigo IS NOT NULL;

/* El codigo AIRHSP identifica de forma univoca el binomio plaza-persona */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_VinculoLaboral_Airhsp' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE UNIQUE INDEX UX_VinculoLaboral_Airhsp ON Personal.VinculoLaboral(VinculoLaboralCodigoAirhsp)
    WHERE VinculoLaboralCodigoAirhsp IS NOT NULL;

/* Un trabajador no puede tener dos vinculos laborales vigentes simultaneos
   (incompatibilidad en el sector publico). Si su realidad admite excepciones,
   elimine este indice. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_VinculoLaboral_Vigente' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE UNIQUE INDEX UX_VinculoLaboral_Vigente ON Personal.VinculoLaboral(TrabajadorId)
    WHERE VinculoLaboralFechaFin IS NULL AND VinculoLaboralEstado = 1;

/* UN SOLO HORARIO VIGENTE POR VINCULO. Materializa la regla
   "un trabajador puede tener un horario" sin impedir el historico. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_AsignacionHorario_Vigente' AND object_id=OBJECT_ID(N'Personal.AsignacionHorario'))
    CREATE UNIQUE INDEX UX_AsignacionHorario_Vigente ON Personal.AsignacionHorario(VinculoLaboralId)
    WHERE AsignacionHorarioFechaFin IS NULL AND AsignacionHorarioEstado = 1;

/* UN SOLO RESPONSABLE VIGENTE por EESS y tipo de responsabilidad. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_ResponsableEess_Vigente' AND object_id=OBJECT_ID(N'Organizacion.ResponsableEess'))
    CREATE UNIQUE INDEX UX_ResponsableEess_Vigente ON Organizacion.ResponsableEess(EessId, TipoResponsabilidadId)
    WHERE ResponsableEessFechaFin IS NULL AND ResponsableEessEstado = 1;

/* Una sola colegiatura marcada como principal por trabajador. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_Colegiatura_Principal' AND object_id=OBJECT_ID(N'Personal.Colegiatura'))
    CREATE UNIQUE INDEX UX_Colegiatura_Principal ON Personal.Colegiatura(TrabajadorId)
    WHERE ColegiaturaEsPrincipal = 1 AND ColegiaturaEstado = 1;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_DocumentoSustento_Hash' AND object_id=OBJECT_ID(N'Soporte.DocumentoSustento'))
    CREATE UNIQUE INDEX UX_DocumentoSustento_Hash ON Soporte.DocumentoSustento(DocumentoSustentoHash)
    WHERE DocumentoSustentoHash IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_ExpedientePad_Numero' AND object_id=OBJECT_ID(N'Disciplina.ExpedientePad'))
    CREATE UNIQUE INDEX UX_ExpedientePad_Numero ON Disciplina.ExpedientePad(ExpedientePadNumero)
    WHERE ExpedientePadNumero IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_ProgramacionPeriodo_Codigo' AND object_id=OBJECT_ID(N'Programacion.ProgramacionPeriodo'))
    CREATE UNIQUE INDEX UX_ProgramacionPeriodo_Codigo ON Programacion.ProgramacionPeriodo(ProgramacionPeriodoCodigo)
    WHERE ProgramacionPeriodoCodigo IS NOT NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'UX_Papeleta_Numero' AND object_id=OBJECT_ID(N'Solicitudes.Papeleta'))
    CREATE UNIQUE INDEX UX_Papeleta_Numero ON Solicitudes.Papeleta(PapeletaNumero)
    WHERE PapeletaNumero IS NOT NULL;
GO

/* ---- 19.2 INDICES DE NAVEGACION Y RENDIMIENTO -------------------------------- */
/* Organizacion */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Eess_MicroredId' AND object_id=OBJECT_ID(N'Organizacion.EstablecimientoSalud'))
    CREATE INDEX IX_Eess_MicroredId ON Organizacion.EstablecimientoSalud(MicroredId) INCLUDE (EessNombre, EessCodigo, EessEstado);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Eess_TipoEstablecimientoId' AND object_id=OBJECT_ID(N'Organizacion.EstablecimientoSalud'))
    CREATE INDEX IX_Eess_TipoEstablecimientoId ON Organizacion.EstablecimientoSalud(TipoEstablecimientoId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ResponsableEess_EessId' AND object_id=OBJECT_ID(N'Organizacion.ResponsableEess'))
    CREATE INDEX IX_ResponsableEess_EessId ON Organizacion.ResponsableEess(EessId, ResponsableEessFechaInicio);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ResponsableEess_VinculoLaboralId' AND object_id=OBJECT_ID(N'Organizacion.ResponsableEess'))
    CREATE INDEX IX_ResponsableEess_VinculoLaboralId ON Organizacion.ResponsableEess(VinculoLaboralId);

/* Configuracion */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_HorarioDetalle_HorarioId' AND object_id=OBJECT_ID(N'Configuracion.HorarioDetalle'))
    CREATE INDEX IX_HorarioDetalle_HorarioId ON Configuracion.HorarioDetalle(HorarioId, HorarioDetalleDia);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_HorarioDetalle_TurnoId' AND object_id=OBJECT_ID(N'Configuracion.HorarioDetalle'))
    CREATE INDEX IX_HorarioDetalle_TurnoId ON Configuracion.HorarioDetalle(TurnoId);

/* Personal */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Trabajador_NumeroDocumento' AND object_id=OBJECT_ID(N'Personal.Trabajador'))
    CREATE INDEX IX_Trabajador_NumeroDocumento ON Personal.Trabajador(TrabajadorNumeroDocumento);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Trabajador_Apellidos' AND object_id=OBJECT_ID(N'Personal.Trabajador'))
    CREATE INDEX IX_Trabajador_Apellidos ON Personal.Trabajador(TrabajadorApellidoPaterno, TrabajadorApellidoMaterno, TrabajadorNombres);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Colegiatura_TrabajadorId' AND object_id=OBJECT_ID(N'Personal.Colegiatura'))
    CREATE INDEX IX_Colegiatura_TrabajadorId ON Personal.Colegiatura(TrabajadorId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_VinculoLaboral_TrabajadorId' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE INDEX IX_VinculoLaboral_TrabajadorId ON Personal.VinculoLaboral(TrabajadorId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_VinculoLaboral_EessId' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE INDEX IX_VinculoLaboral_EessId ON Personal.VinculoLaboral(EessId, VinculoLaboralEstado) INCLUDE (TrabajadorId, CargoId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_VinculoLaboral_CondicionLaboralId' AND object_id=OBJECT_ID(N'Personal.VinculoLaboral'))
    CREATE INDEX IX_VinculoLaboral_CondicionLaboralId ON Personal.VinculoLaboral(CondicionLaboralId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_AsignacionHorario_VinculoLaboralId' AND object_id=OBJECT_ID(N'Personal.AsignacionHorario'))
    CREATE INDEX IX_AsignacionHorario_VinculoLaboralId ON Personal.AsignacionHorario(VinculoLaboralId, AsignacionHorarioFechaInicio);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_AsignacionHorario_HorarioId' AND object_id=OBJECT_ID(N'Personal.AsignacionHorario'))
    CREATE INDEX IX_AsignacionHorario_HorarioId ON Personal.AsignacionHorario(HorarioId);

/* Seguridad */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_UsuarioRol_RolId' AND object_id=OBJECT_ID(N'Seguridad.UsuarioRol'))
    CREATE INDEX IX_UsuarioRol_RolId ON Seguridad.UsuarioRol(RolId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_UsuarioAmbito_MicroredId' AND object_id=OBJECT_ID(N'Seguridad.UsuarioAmbito'))
    CREATE INDEX IX_UsuarioAmbito_MicroredId ON Seguridad.UsuarioAmbito(MicroredId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_UsuarioAmbito_EessId' AND object_id=OBJECT_ID(N'Seguridad.UsuarioAmbito'))
    CREATE INDEX IX_UsuarioAmbito_EessId ON Seguridad.UsuarioAmbito(EessId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_SesionAcceso_UsuarioFecha' AND object_id=OBJECT_ID(N'Seguridad.SesionAcceso'))
    CREATE INDEX IX_SesionAcceso_UsuarioFecha ON Seguridad.SesionAcceso(UsuarioId, SesionAccesoFechaInicio);

/* Biometria */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_PlantillaBiometrica_TrabajadorId' AND object_id=OBJECT_ID(N'Biometria.PlantillaBiometrica'))
    CREATE INDEX IX_PlantillaBiometrica_TrabajadorId ON Biometria.PlantillaBiometrica(TrabajadorId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_DispositivoMarcacion_EessId' AND object_id=OBJECT_ID(N'Biometria.DispositivoMarcacion'))
    CREATE INDEX IX_DispositivoMarcacion_EessId ON Biometria.DispositivoMarcacion(EessId);

/* Programacion */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ProgramacionPeriodo_UnidadPeriodo' AND object_id=OBJECT_ID(N'Programacion.ProgramacionPeriodo'))
    CREATE INDEX IX_ProgramacionPeriodo_UnidadPeriodo ON Programacion.ProgramacionPeriodo(EessId, ProgramacionPeriodoAnio, ProgramacionPeriodoMes) INCLUDE (ProgramacionPeriodoEstado);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ProgramacionPeriodo_Fechas' AND object_id=OBJECT_ID(N'Programacion.ProgramacionPeriodo'))
    CREATE INDEX IX_ProgramacionPeriodo_Fechas ON Programacion.ProgramacionPeriodo(ProgramacionPeriodoFechaInicio, ProgramacionPeriodoFechaFin);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ProgramacionTrabajador_VinculoLaboralId' AND object_id=OBJECT_ID(N'Programacion.ProgramacionTrabajador'))
    CREATE INDEX IX_ProgramacionTrabajador_VinculoLaboralId ON Programacion.ProgramacionTrabajador(VinculoLaboralId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_TurnoProgramado_Fecha' AND object_id=OBJECT_ID(N'Programacion.TurnoProgramado'))
    CREATE INDEX IX_TurnoProgramado_Fecha ON Programacion.TurnoProgramado(TurnoProgramadoFecha) INCLUDE (TurnoId, ProgramacionTrabajadorId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_TurnoProgramado_TurnoId' AND object_id=OBJECT_ID(N'Programacion.TurnoProgramado'))
    CREATE INDEX IX_TurnoProgramado_TurnoId ON Programacion.TurnoProgramado(TurnoId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_CambioTurno_TurnoProgramadoId' AND object_id=OBJECT_ID(N'Programacion.CambioTurno'))
    CREATE INDEX IX_CambioTurno_TurnoProgramadoId ON Programacion.CambioTurno(TurnoProgramadoId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_CambioTurno_Solicitante' AND object_id=OBJECT_ID(N'Programacion.CambioTurno'))
    CREATE INDEX IX_CambioTurno_Solicitante ON Programacion.CambioTurno(VinculoLaboralSolicitanteId, CambioTurnoEstado);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_CargaProgramacion_UnidadPeriodo' AND object_id=OBJECT_ID(N'Programacion.CargaProgramacion'))
    CREATE INDEX IX_CargaProgramacion_UnidadPeriodo ON Programacion.CargaProgramacion(EessId, CargaProgramacionAnio, CargaProgramacionMes);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_CargaProgramacion_DocumentoSustentoId' AND object_id=OBJECT_ID(N'Programacion.CargaProgramacion'))
    CREATE INDEX IX_CargaProgramacion_DocumentoSustentoId ON Programacion.CargaProgramacion(DocumentoSustentoId);

/* Asistencia */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Marcacion_VinculoFecha' AND object_id=OBJECT_ID(N'Asistencia.Marcacion'))
    CREATE INDEX IX_Marcacion_VinculoFecha ON Asistencia.Marcacion(VinculoLaboralId, MarcacionFechaHora) INCLUDE (MarcacionTipo, MarcacionEsValida);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Marcacion_FechaHora' AND object_id=OBJECT_ID(N'Asistencia.Marcacion'))
    CREATE INDEX IX_Marcacion_FechaHora ON Asistencia.Marcacion(MarcacionFechaHora);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_AsistenciaDiaria_VinculoFecha' AND object_id=OBJECT_ID(N'Asistencia.AsistenciaDiaria'))
    CREATE INDEX IX_AsistenciaDiaria_VinculoFecha ON Asistencia.AsistenciaDiaria(VinculoLaboralId, AsistenciaDiariaFecha) INCLUDE (EstadoAsistenciaId, JustificacionFaltaId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_AsistenciaDiaria_Estado' AND object_id=OBJECT_ID(N'Asistencia.AsistenciaDiaria'))
    CREATE INDEX IX_AsistenciaDiaria_Estado ON Asistencia.AsistenciaDiaria(EstadoAsistenciaId, AsistenciaDiariaFecha);

/* Faltas pendientes de justificar: consulta frecuente del responsable del EESS */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_AsistenciaDiaria_SinJustificar' AND object_id=OBJECT_ID(N'Asistencia.AsistenciaDiaria'))
    CREATE INDEX IX_AsistenciaDiaria_SinJustificar ON Asistencia.AsistenciaDiaria(VinculoLaboralId, AsistenciaDiariaFecha)
    WHERE JustificacionFaltaId IS NULL;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_JustificacionFalta_VinculoFecha' AND object_id=OBJECT_ID(N'Asistencia.JustificacionFalta'))
    CREATE INDEX IX_JustificacionFalta_VinculoFecha ON Asistencia.JustificacionFalta(VinculoLaboralId, JustificacionFaltaFechaInicio, JustificacionFaltaFechaFin);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_JustificacionFalta_Estado' AND object_id=OBJECT_ID(N'Asistencia.JustificacionFalta'))
    CREATE INDEX IX_JustificacionFalta_Estado ON Asistencia.JustificacionFalta(JustificacionFaltaEstado, JustificacionFaltaFechaRegistro);

/* Solicitudes */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Papeleta_VinculoFecha' AND object_id=OBJECT_ID(N'Solicitudes.Papeleta'))
    CREATE INDEX IX_Papeleta_VinculoFecha ON Solicitudes.Papeleta(VinculoLaboralId, PapeletaFecha) INCLUDE (TipoPapeletaId, PapeletaEstado);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Papeleta_TipoPapeletaId' AND object_id=OBJECT_ID(N'Solicitudes.Papeleta'))
    CREATE INDEX IX_Papeleta_TipoPapeletaId ON Solicitudes.Papeleta(TipoPapeletaId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Licencia_VinculoFecha' AND object_id=OBJECT_ID(N'Solicitudes.Licencia'))
    CREATE INDEX IX_Licencia_VinculoFecha ON Solicitudes.Licencia(VinculoLaboralId, LicenciaFechaInicio);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_DescansoMedico_VinculoFecha' AND object_id=OBJECT_ID(N'Solicitudes.DescansoMedico'))
    CREATE INDEX IX_DescansoMedico_VinculoFecha ON Solicitudes.DescansoMedico(VinculoLaboralId, DescansoMedicoFechaInicio);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_OcurrenciaPorteria_UnidadFecha' AND object_id=OBJECT_ID(N'Solicitudes.OcurrenciaPorteria'))
    CREATE INDEX IX_OcurrenciaPorteria_UnidadFecha ON Solicitudes.OcurrenciaPorteria(EessId, OcurrenciaPorteriaFechaHora);

/* Vacaciones / Compensaciones / Consolidacion */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_PeriodoVacacional_VinculoId' AND object_id=OBJECT_ID(N'Vacaciones.PeriodoVacacional'))
    CREATE INDEX IX_PeriodoVacacional_VinculoId ON Vacaciones.PeriodoVacacional(VinculoLaboralId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_CompensacionHoraria_VinculoFecha' AND object_id=OBJECT_ID(N'Compensaciones.CompensacionHoraria'))
    CREATE INDEX IX_CompensacionHoraria_VinculoFecha ON Compensaciones.CompensacionHoraria(VinculoLaboralId, CompensacionHorariaFechaGeneracion);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ConsolidadoAsistencia_PeriodoId' AND object_id=OBJECT_ID(N'Consolidacion.ConsolidadoAsistencia'))
    CREATE INDEX IX_ConsolidadoAsistencia_PeriodoId ON Consolidacion.ConsolidadoAsistencia(PeriodoAsistenciaId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ConsolidadoAsistencia_VinculoId' AND object_id=OBJECT_ID(N'Consolidacion.ConsolidadoAsistencia'))
    CREATE INDEX IX_ConsolidadoAsistencia_VinculoId ON Consolidacion.ConsolidadoAsistencia(VinculoLaboralId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_DetalleConsolidado_ConsolidadoId' AND object_id=OBJECT_ID(N'Consolidacion.DetalleConsolidado'))
    CREATE INDEX IX_DetalleConsolidado_ConsolidadoId ON Consolidacion.DetalleConsolidado(ConsolidadoAsistenciaId);

/* Disciplina / Soporte */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_ExpedientePad_VinculoLaboralId' AND object_id=OBJECT_ID(N'Disciplina.ExpedientePad'))
    CREATE INDEX IX_ExpedientePad_VinculoLaboralId ON Disciplina.ExpedientePad(VinculoLaboralId);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_SupervisionInopinada_UnidadFecha' AND object_id=OBJECT_ID(N'Disciplina.SupervisionInopinada'))
    CREATE INDEX IX_SupervisionInopinada_UnidadFecha ON Disciplina.SupervisionInopinada(EessId, SupervisionInopinadaFechaHora);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Auditoria_FechaHora' AND object_id=OBJECT_ID(N'Seguridad.Auditoria'))
    CREATE INDEX IX_Auditoria_FechaHora ON Seguridad.Auditoria(AuditoriaFechaHora);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Auditoria_UsuarioFecha' AND object_id=OBJECT_ID(N'Seguridad.Auditoria'))
    CREATE INDEX IX_Auditoria_UsuarioFecha ON Seguridad.Auditoria(UsuarioId, AuditoriaFechaHora);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_Notificacion_UsuarioLeida' AND object_id=OBJECT_ID(N'Soporte.Notificacion'))
    CREATE INDEX IX_Notificacion_UsuarioLeida ON Soporte.Notificacion(UsuarioId, NotificacionLeida, NotificacionFecha);
GO

/* ================================================================================
   20. CATALOGOS - DATOS SEMILLA
   --------------------------------------------------------------------------------
   Todas las inserciones son IDEMPOTENTES (NOT EXISTS sobre el codigo).
   Puede ejecutarse el script tantas veces como sea necesario.
   ================================================================================ */

/* ---- 20.1 Tipos de establecimiento ---- */
INSERT INTO Organizacion.TipoEstablecimiento (TipoEstablecimientoCodigo, TipoEstablecimientoNombre, TipoEstablecimientoDescripcion)
SELECT v.Codigo, v.Nombre, v.Descripcion
FROM (VALUES
    (N'CS',          N'Centro de Salud',           N'Establecimiento con poblacion asignada y mayor capacidad resolutiva'),
    (N'PS',          N'Puesto de Salud',           N'Establecimiento del primer nivel de atencion'),
    (N'CSMC',        N'Centro de Salud Mental',    N'Centro de salud mental comunitaria'),
    (N'HOSPITAL',    N'Hospital',                  N'Establecimiento del segundo nivel de atencion'),
    (N'UNIDAD_ADMIN',N'Unidad Administrativa',     N'Oficina de la sede: RRHH, logistica, administracion')
) AS v(Codigo, Nombre, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Organizacion.TipoEstablecimiento t WHERE t.TipoEstablecimientoCodigo = v.Codigo);
GO

/* ---- 20.1.b MICROREDES (distritos)  *** EDITE ESTA LISTA CON SUS DATOS REALES ***
   Se incluyen como ejemplo las mencionadas por el area usuaria. Agregue o quite
   filas segun la conformacion vigente de la Red de Salud Trujillo. ---- */
INSERT INTO Organizacion.Microred (MicroredCodigo, MicroredNombre, MicroredDistrito)
SELECT v.Codigo, v.Nombre, v.Distrito
FROM (VALUES
    (N'MR-LE',   N'Microred La Esperanza',      N'La Esperanza'),
    (N'MR-EP',   N'Microred El Porvenir',       N'El Porvenir'),
    (N'MR-FM',   N'Microred Florencia de Mora', N'Florencia de Mora'),
    (N'MR-SEDE', N'Sede Administrativa',        N'Trujillo')
) AS v(Codigo, Nombre, Distrito)
WHERE NOT EXISTS (SELECT 1 FROM Organizacion.Microred m WHERE m.MicroredCodigo = v.Codigo);
GO

/* ---- 20.1.c ESTABLECIMIENTOS DE SALUD  *** EDITE ESTA LISTA CON SUS DATOS REALES ***
   Cada EESS declara a que Microred pertenece mediante MicroredCodigo. ---- */
INSERT INTO Organizacion.EstablecimientoSalud (MicroredId, TipoEstablecimientoId, EessCodigo, EessNombre, EessCategoria)
SELECT m.MicroredId, te.TipoEstablecimientoId, v.Codigo, v.Nombre, v.Categoria
FROM (VALUES
    (N'MR-LE',   N'CS',           N'EESS-LE-01',   N'C.S. La Esperanza',            N'I-4'),
    (N'MR-EP',   N'CS',           N'EESS-EP-01',   N'C.S. El Porvenir',             N'I-4'),
    (N'MR-FM',   N'CS',           N'EESS-FM-01',   N'C.S. Florencia de Mora',       N'I-3'),
    (N'MR-SEDE', N'UNIDAD_ADMIN', N'SEDE-RRHH',    N'Oficina de Recursos Humanos',  NULL)
) AS v(MicroredCodigo, TipoCodigo, Codigo, Nombre, Categoria)
INNER JOIN Organizacion.Microred m ON m.MicroredCodigo = v.MicroredCodigo
INNER JOIN Organizacion.TipoEstablecimiento te ON te.TipoEstablecimientoCodigo = v.TipoCodigo
WHERE NOT EXISTS (SELECT 1 FROM Organizacion.EstablecimientoSalud e WHERE e.EessCodigo = v.Codigo);
GO

/* ---- 20.2 Tipos de responsabilidad sobre una unidad ---- */
INSERT INTO Organizacion.TipoResponsabilidad (TipoResponsabilidadCodigo, TipoResponsabilidadNombre, TipoResponsabilidadDescripcion)
SELECT v.Codigo, v.Nombre, v.Descripcion
FROM (VALUES
    (N'JEFE_EESS',        N'Jefe del Establecimiento de Salud', N'Maxima autoridad del EESS'),
    (N'RESP_PERSONAL',    N'Responsable del Personal',          N'Responsable del control de personal y asistencia del EESS'),
    (N'RESP_PROGRAMACION',N'Responsable de Programacion',       N'Elabora y remite la programacion de turnos'),
    (N'RESP_ASISTENCIA',  N'Responsable de Asistencia',         N'Valida marcaciones y justificaciones del EESS'),
    (N'COORDINADOR',      N'Coordinador',                       N'Coordinacion funcional')
) AS v(Codigo, Nombre, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Organizacion.TipoResponsabilidad t WHERE t.TipoResponsabilidadCodigo = v.Codigo);
GO

/* ---- 20.3 Tipos de documento de identidad ---- */
INSERT INTO Personal.TipoDocumentoIdentidad (TipoDocumentoIdentidadCodigo, TipoDocumentoIdentidadNombre, TipoDocumentoIdentidadAbreviatura, TipoDocumentoIdentidadLongitud)
SELECT v.Codigo, v.Nombre, v.Abrev, v.Longitud
FROM (VALUES
    (N'DNI',  N'Documento Nacional de Identidad', N'DNI', 8),
    (N'CE',   N'Carne de Extranjeria',            N'CE',  12),
    (N'PAS',  N'Pasaporte',                       N'PAS', 12),
    (N'PTP',  N'Permiso Temporal de Permanencia', N'PTP', 12)
) AS v(Codigo, Nombre, Abrev, Longitud)
WHERE NOT EXISTS (SELECT 1 FROM Personal.TipoDocumentoIdentidad t WHERE t.TipoDocumentoIdentidadCodigo = v.Codigo);
GO

/* ---- 20.4 Regimen laboral (MARCO LEGAL) ---- */
INSERT INTO Personal.RegimenLaboral (RegimenLaboralCodigo, RegimenLaboralNombre, RegimenLaboralBaseLegal)
SELECT v.Codigo, v.Nombre, v.BaseLegal
FROM (VALUES
    (N'DL276',  N'Regimen Publico - D.L. 276',        N'Decreto Legislativo N 276'),
    (N'DL728',  N'Regimen Privado - D.L. 728',        N'Decreto Legislativo N 728'),
    (N'DL1057', N'Contrato Administrativo de Servicios', N'Decreto Legislativo N 1057 (CAS)'),
    (N'DL1153', N'Personal de la Salud - D.L. 1153',  N'Decreto Legislativo N 1153'),
    (N'SERVIR', N'Servicio Civil - Ley 30057',        N'Ley N 30057'),
    (N'OTRO',   N'Otro regimen',                      NULL)
) AS v(Codigo, Nombre, BaseLegal)
WHERE NOT EXISTS (SELECT 1 FROM Personal.RegimenLaboral t WHERE t.RegimenLaboralCodigo = v.Codigo);
GO

/* ---- 20.5 CONDICION LABORAL (punto 4 del encargo) ----
   Catalogo ampliable: agregar una condicion nueva es un INSERT, no un cambio de esquema. */
INSERT INTO Personal.CondicionLaboral (CondicionLaboralCodigo, CondicionLaboralNombre, CondicionLaboralEsPermanente, CondicionLaboralRequiereAirhsp, CondicionLaboralDescripcion)
SELECT v.Codigo, v.Nombre, v.EsPerm, v.Airhsp, v.Descripcion
FROM (VALUES
    (N'NOMBRADO',         N'Nombrado',               1, 1, N'Servidor nombrado en plaza organica'),
    (N'CONTRATADO',       N'Contratado',             0, 1, N'Contratado a plazo determinado'),
    (N'CAS',              N'CAS',                    0, 1, N'Contrato Administrativo de Servicios'),
    (N'CAS_INDETERMINADO',N'CAS Indeterminado',      1, 1, N'CAS a plazo indeterminado'),
    (N'SERUMS_REM',       N'SERUMS Remunerado',      0, 1, N'Servicio Rural y Urbano Marginal de Salud remunerado'),
    (N'SERUMS_EQUIV',     N'SERUMS Equivalente',     0, 0, N'SERUMS equivalente, no remunerado por la entidad'),
    (N'DESTACADO',        N'Destacado',              0, 0, N'Personal destacado desde otra entidad'),
    (N'TERCEROS',         N'Locacion de Servicios',  0, 0, N'Prestacion de servicios por terceros'),
    (N'INTERNO',          N'Interno / Practicante',  0, 0, N'Internado o practicas preprofesionales'),
    (N'RESIDENTE',        N'Medico Residente',       0, 0, N'Residentado medico'),
    (N'OTRO',             N'Otra condicion',         0, 0, NULL)
) AS v(Codigo, Nombre, EsPerm, Airhsp, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Personal.CondicionLaboral t WHERE t.CondicionLaboralCodigo = v.Codigo);
GO

/* ---- 20.6 Grupo ocupacional ---- */
INSERT INTO Personal.GrupoOcupacional (GrupoOcupacionalCodigo, GrupoOcupacionalNombre)
SELECT v.Codigo, v.Nombre
FROM (VALUES
    (N'FUNCIONARIO',  N'Funcionario'),
    (N'PROFESIONAL',  N'Profesional de la Salud'),
    (N'PROF_ADM',     N'Profesional Administrativo'),
    (N'TECNICO',      N'Tecnico'),
    (N'AUXILIAR',     N'Auxiliar'),
    (N'ASISTENCIAL',  N'Asistencial')
) AS v(Codigo, Nombre)
WHERE NOT EXISTS (SELECT 1 FROM Personal.GrupoOcupacional t WHERE t.GrupoOcupacionalCodigo = v.Codigo);
GO

/* ---- 20.7 PROFESION (nueva entidad) ---- */
INSERT INTO Personal.Profesion (ProfesionCodigo, ProfesionNombre, ProfesionRequiereColegiatura)
SELECT v.Codigo, v.Nombre, v.Colegiatura
FROM (VALUES
    (N'MEDICO',      N'Medico Cirujano',            1),
    (N'ENFERMERIA',  N'Enfermeria',                 1),
    (N'OBSTETRICIA', N'Obstetricia',                1),
    (N'ODONTOLOGIA', N'Odontologia',                1),
    (N'PSICOLOGIA',  N'Psicologia',                 1),
    (N'NUTRICION',   N'Nutricion',                  1),
    (N'FARMACIA',    N'Quimico Farmaceutico',       1),
    (N'BIOLOGIA',    N'Biologia',                   1),
    (N'TECMED',      N'Tecnologia Medica',          1),
    (N'TRABSOCIAL',  N'Trabajo Social',             1),
    (N'CONTABILIDAD',N'Contabilidad',               1),
    (N'ADMIN',       N'Administracion',             1),
    (N'INGSISTEMAS', N'Ingenieria de Sistemas',     1),
    (N'DERECHO',     N'Derecho',                    1),
    (N'TEC_ENF',     N'Tecnico en Enfermeria',      0),
    (N'TEC_ADM',     N'Tecnico Administrativo',     0),
    (N'TEC_LAB',     N'Tecnico de Laboratorio',     0),
    (N'SIN_PROF',    N'Sin profesion registrada',   0)
) AS v(Codigo, Nombre, Colegiatura)
WHERE NOT EXISTS (SELECT 1 FROM Personal.Profesion t WHERE t.ProfesionCodigo = v.Codigo);
GO

/* ---- 20.8 TIPOS DE COLEGIATURA (punto 8 del encargo) ---- */
INSERT INTO Personal.ColegiaturaTipo (ColegiaturaTipoCodigo, ColegiaturaTipoNombre, ColegiaturaTipoEntidad, ProfesionId)
SELECT v.Codigo, v.Nombre, v.Entidad, p.ProfesionId
FROM (VALUES
    (N'CMP',   N'Colegio Medico del Peru',                  N'Colegio Medico del Peru',                  N'MEDICO'),
    (N'CEP',   N'Colegio de Enfermeros del Peru',           N'Colegio de Enfermeros del Peru',           N'ENFERMERIA'),
    (N'COP',   N'Colegio de Obstetras del Peru',            N'Colegio de Obstetras del Peru',            N'OBSTETRICIA'),
    (N'COD',   N'Colegio Odontologico del Peru',            N'Colegio Odontologico del Peru',            N'ODONTOLOGIA'),
    (N'CPSP',  N'Colegio de Psicologos del Peru',           N'Colegio de Psicologos del Peru',           N'PSICOLOGIA'),
    (N'CNP',   N'Colegio de Nutricionistas del Peru',       N'Colegio de Nutricionistas del Peru',       N'NUTRICION'),
    (N'CQFP',  N'Colegio Quimico Farmaceutico del Peru',    N'Colegio Quimico Farmaceutico del Peru',    N'FARMACIA'),
    (N'CBP',   N'Colegio de Biologos del Peru',             N'Colegio de Biologos del Peru',             N'BIOLOGIA'),
    (N'CTMP',  N'Colegio Tecnologo Medico del Peru',        N'Colegio Tecnologo Medico del Peru',        N'TECMED'),
    (N'CTSP',  N'Colegio de Trabajadores Sociales del Peru',N'Colegio de Trabajadores Sociales del Peru',N'TRABSOCIAL'),
    (N'CCPP',  N'Colegio de Contadores Publicos',           N'Colegio de Contadores Publicos',           N'CONTABILIDAD'),
    (N'CLAD',  N'Colegio de Licenciados en Administracion', N'Colegio de Licenciados en Administracion', N'ADMIN'),
    (N'CIP',   N'Colegio de Ingenieros del Peru',           N'Colegio de Ingenieros del Peru',           N'INGSISTEMAS'),
    (N'CAL',   N'Colegio de Abogados',                      N'Colegio de Abogados',                      N'DERECHO')
) AS v(Codigo, Nombre, Entidad, ProfCodigo)
LEFT JOIN Personal.Profesion p ON p.ProfesionCodigo = v.ProfCodigo
WHERE NOT EXISTS (SELECT 1 FROM Personal.ColegiaturaTipo t WHERE t.ColegiaturaTipoCodigo = v.Codigo);
GO

/* ---- 20.9 Tipos de jornada ---- */
INSERT INTO Configuracion.TipoJornada (TipoJornadaCodigo, TipoJornadaNombre, TipoJornadaDescripcion)
SELECT v.Codigo, v.Nombre, v.Descripcion
FROM (VALUES
    (N'ADMIN',     N'Jornada Administrativa', N'Jornada de lunes a viernes en horario de oficina'),
    (N'ASISTENC',  N'Jornada Asistencial',    N'Jornada del personal asistencial con rotacion'),
    (N'GUARDIA',   N'Guardia',                N'Jornada de guardia de 12 o 24 horas'),
    (N'PARCIAL',   N'Jornada Parcial',        N'Jornada de tiempo parcial')
) AS v(Codigo, Nombre, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Configuracion.TipoJornada t WHERE t.TipoJornadaCodigo = v.Codigo);
GO

INSERT INTO Configuracion.ParametroJornada (TipoJornadaId, ParametroJornadaHorasDiarias, ParametroJornadaHorasSemanales, ParametroJornadaHorasMensuales, ParametroJornadaVigenciaDesde)
SELECT j.TipoJornadaId, v.Diarias, v.Semanales, v.Mensuales, '2020-01-01'
FROM (VALUES
    (N'ADMIN',    CONVERT(DECIMAL(5,2),8.00),  CONVERT(DECIMAL(6,2),48.00), CONVERT(DECIMAL(7,2),192.00)),
    (N'ASISTENC', CONVERT(DECIMAL(5,2),6.00),  CONVERT(DECIMAL(6,2),36.00), CONVERT(DECIMAL(7,2),150.00)),
    (N'GUARDIA',  CONVERT(DECIMAL(5,2),12.00), CONVERT(DECIMAL(6,2),36.00), CONVERT(DECIMAL(7,2),150.00)),
    (N'PARCIAL',  CONVERT(DECIMAL(5,2),4.00),  CONVERT(DECIMAL(6,2),24.00), CONVERT(DECIMAL(7,2),96.00))
) AS v(Codigo, Diarias, Semanales, Mensuales)
INNER JOIN Configuracion.TipoJornada j ON j.TipoJornadaCodigo = v.Codigo
WHERE NOT EXISTS (
    SELECT 1 FROM Configuracion.ParametroJornada p
    WHERE p.TipoJornadaId = j.TipoJornadaId AND p.ParametroJornadaVigenciaDesde = '2020-01-01');
GO

/* ---- 20.10 Tabla y tramos de tolerancia ---- */
INSERT INTO Configuracion.TablaTolerancia (TablaToleranciaCodigo, TablaToleranciaNombre, TablaToleranciaDescripcion)
SELECT v.Codigo, v.Nombre, v.Descripcion
FROM (VALUES
    (N'RIT_GENERAL', N'Escala general RIT', N'Escala de clasificacion y descuento por tardanza segun el Reglamento Interno de Trabajo')
) AS v(Codigo, Nombre, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Configuracion.TablaTolerancia t WHERE t.TablaToleranciaCodigo = v.Codigo);
GO

INSERT INTO Configuracion.TramoTolerancia (TablaToleranciaId, TramoToleranciaTipo, TramoToleranciaMinutosDesde, TramoToleranciaMinutosHasta, TramoToleranciaFactorDescuento, TramoToleranciaDescripcion)
SELECT t.TablaToleranciaId, v.Tipo, v.Desde, v.Hasta, v.Factor, v.Descripcion
FROM (VALUES
    (N'TARDANZA', 1,  5,    CONVERT(DECIMAL(5,2),0.00), N'Dentro de la tolerancia'),
    (N'TARDANZA', 6,  20,   CONVERT(DECIMAL(5,2),1.00), N'Tardanza leve: descuento proporcional'),
    (N'TARDANZA', 21, 60,   CONVERT(DECIMAL(5,2),1.50), N'Tardanza grave'),
    (N'TARDANZA', 61, NULL, CONVERT(DECIMAL(5,2),2.00), N'Tardanza muy grave / se evalua como inasistencia')
) AS v(Tipo, Desde, Hasta, Factor, Descripcion)
CROSS JOIN Configuracion.TablaTolerancia t
WHERE t.TablaToleranciaCodigo = N'RIT_GENERAL'
  AND NOT EXISTS (
      SELECT 1 FROM Configuracion.TramoTolerancia x
      WHERE x.TablaToleranciaId = t.TablaToleranciaId
        AND x.TramoToleranciaTipo = v.Tipo
        AND x.TramoToleranciaMinutosDesde = v.Desde);
GO

/* ---- 20.11 Turnos base ---- */
INSERT INTO Configuracion.Turno (TipoJornadaId, TurnoCodigo, TurnoNombre, TurnoHoraEntrada, TurnoHoraSalida, TurnoToleranciaEntradaMinutos, TurnoEsGuardia)
SELECT j.TipoJornadaId, v.Codigo, v.Nombre, v.Entrada, v.Salida, v.Tolerancia, v.EsGuardia
FROM (VALUES
    (N'ADMIN',    N'ADM-D',  N'Administrativo diurno 07:45-15:45', CONVERT(TIME(0),'07:45'), CONVERT(TIME(0),'15:45'), 5, CONVERT(BIT,0)),
    (N'ASISTENC', N'M',      N'Manana 07:00-13:00',                CONVERT(TIME(0),'07:00'), CONVERT(TIME(0),'13:00'), 5, CONVERT(BIT,0)),
    (N'ASISTENC', N'T',      N'Tarde 13:00-19:00',                 CONVERT(TIME(0),'13:00'), CONVERT(TIME(0),'19:00'), 5, CONVERT(BIT,0)),
    (N'GUARDIA',  N'N',      N'Noche 19:00-07:00',                 CONVERT(TIME(0),'19:00'), CONVERT(TIME(0),'07:00'), 5, CONVERT(BIT,1)),
    (N'GUARDIA',  N'G12-D',  N'Guardia diurna 12h 07:00-19:00',    CONVERT(TIME(0),'07:00'), CONVERT(TIME(0),'19:00'), 5, CONVERT(BIT,1)),
    (N'GUARDIA',  N'G24',    N'Guardia 24 horas',                  CONVERT(TIME(0),'08:00'), CONVERT(TIME(0),'08:00'), 5, CONVERT(BIT,1))
) AS v(Jornada, Codigo, Nombre, Entrada, Salida, Tolerancia, EsGuardia)
INNER JOIN Configuracion.TipoJornada j ON j.TipoJornadaCodigo = v.Jornada
WHERE NOT EXISTS (SELECT 1 FROM Configuracion.Turno t WHERE t.TurnoCodigo = v.Codigo);
GO

/* ---- 20.12 Horario administrativo base (plantilla de ejemplo) ---- */
INSERT INTO Configuracion.Horario (TipoJornadaId, HorarioCodigo, HorarioNombre, HorarioDescripcion)
SELECT j.TipoJornadaId, N'HOR-ADM-LV', N'Administrativo Lunes a Viernes', N'07:45 a 15:45 de lunes a viernes'
FROM Configuracion.TipoJornada j
WHERE j.TipoJornadaCodigo = N'ADMIN'
  AND NOT EXISTS (SELECT 1 FROM Configuracion.Horario h WHERE h.HorarioCodigo = N'HOR-ADM-LV');
GO

INSERT INTO Configuracion.HorarioDetalle (HorarioId, TurnoId, HorarioDetalleDia)
SELECT h.HorarioId, t.TurnoId, d.Dia
FROM Configuracion.Horario h
CROSS JOIN Configuracion.Turno t
CROSS JOIN (VALUES (CONVERT(TINYINT,1)),(CONVERT(TINYINT,2)),(CONVERT(TINYINT,3)),(CONVERT(TINYINT,4)),(CONVERT(TINYINT,5))) AS d(Dia)
WHERE h.HorarioCodigo = N'HOR-ADM-LV'
  AND t.TurnoCodigo   = N'ADM-D'
  AND NOT EXISTS (
      SELECT 1 FROM Configuracion.HorarioDetalle x
      WHERE x.HorarioId = h.HorarioId AND x.TurnoId = t.TurnoId AND x.HorarioDetalleDia = d.Dia);
GO

/* ---- 20.13 Metodos de marcacion ---- */
INSERT INTO Biometria.MetodoMarcacion (MetodoMarcacionCodigo, MetodoMarcacionNombre)
SELECT v.Codigo, v.Nombre
FROM (VALUES
    (N'HUELLA',  N'Huella dactilar'),
    (N'ROSTRO',  N'Reconocimiento facial'),
    (N'TARJETA', N'Tarjeta de proximidad'),
    (N'CLAVE',   N'Codigo o clave'),
    (N'MANUAL',  N'Registro manual'),
    (N'APP',     N'Aplicativo movil con geolocalizacion')
) AS v(Codigo, Nombre)
WHERE NOT EXISTS (SELECT 1 FROM Biometria.MetodoMarcacion t WHERE t.MetodoMarcacionCodigo = v.Codigo);
GO

/* ---- 20.14 Tipos de periodo de programacion (punto 10 del encargo) ----
   Mensual y quincenal SON EL MISMO CONCEPTO con distinto rango de fechas.
   Por eso son filas de catalogo, no tablas distintas. */
INSERT INTO Programacion.TipoPeriodoProgramacion (TipoPeriodoProgramacionCodigo, TipoPeriodoProgramacionNombre, TipoPeriodoProgramacionDias)
SELECT v.Codigo, v.Nombre, v.Dias
FROM (VALUES
    (N'MENSUAL',    N'Programacion mensual',    30),
    (N'QUINCENAL',  N'Programacion quincenal',  15),
    (N'SEMANAL',    N'Programacion semanal',     7),
    (N'EXTRAORD',   N'Programacion extraordinaria', NULL)
) AS v(Codigo, Nombre, Dias)
WHERE NOT EXISTS (SELECT 1 FROM Programacion.TipoPeriodoProgramacion t WHERE t.TipoPeriodoProgramacionCodigo = v.Codigo);
GO

/* ---- 20.15 Tipos de cambio de turno (reemplazan a Reprogramacion + CambioTurno) ---- */
INSERT INTO Programacion.TipoCambioTurno (TipoCambioTurnoCodigo, TipoCambioTurnoNombre, TipoCambioTurnoRequiereReemplazante, TipoCambioTurnoDescripcion)
SELECT v.Codigo, v.Nombre, v.Reemp, v.Descripcion
FROM (VALUES
    (N'REPROGRAMACION', N'Reprogramacion',  0, N'La jefatura modifica el turno originalmente programado'),
    (N'PERMUTA',        N'Permuta',         1, N'Dos trabajadores intercambian sus turnos'),
    (N'REEMPLAZO',      N'Reemplazo',       1, N'Otro trabajador cubre el turno'),
    (N'ANULACION',      N'Anulacion',       0, N'Se deja sin efecto el turno programado')
) AS v(Codigo, Nombre, Reemp, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Programacion.TipoCambioTurno t WHERE t.TipoCambioTurnoCodigo = v.Codigo);
GO

/* ---- 20.16 Estados de asistencia ----
   EsFalta = 1 marca los estados JUSTIFICABLES por el modulo de justificacion. */
INSERT INTO Asistencia.EstadoAsistencia (EstadoAsistenciaCodigo, EstadoAsistenciaNombre, EstadoAsistenciaEsFalta, EstadoAsistenciaEsDescontable, EstadoAsistenciaEsLaborable)
SELECT v.Codigo, v.Nombre, v.EsFalta, v.EsDesc, v.EsLab
FROM (VALUES
    (N'ASISTIO',        N'Asistio',                        0, 0, 1),
    (N'TARDANZA',       N'Tardanza',                       0, 1, 1),
    (N'SALIDA_ANTIC',   N'Salida anticipada',              0, 1, 1),
    (N'FALTA',          N'Falta injustificada',            1, 1, 1),
    (N'FALTA_JUST',     N'Falta justificada',              1, 0, 1),
    (N'OMISION_MARCA',  N'Omision de marcacion',           1, 0, 1),
    (N'PAPELETA',       N'Con papeleta autorizada',        0, 0, 1),
    (N'COMISION',       N'Comision de servicio',           0, 0, 1),
    (N'LICENCIA',       N'Con licencia',                   0, 0, 1),
    (N'DESCANSO_MED',   N'Descanso medico',                0, 0, 1),
    (N'VACACIONES',     N'Vacaciones',                     0, 0, 1),
    (N'DESCANSO',       N'Dia de descanso programado',     0, 0, 0),
    (N'NO_LABORABLE',   N'Dia no laborable / feriado',     0, 0, 0),
    (N'CAPACITACION',   N'Capacitacion autorizada',        0, 0, 1)
) AS v(Codigo, Nombre, EsFalta, EsDesc, EsLab)
WHERE NOT EXISTS (SELECT 1 FROM Asistencia.EstadoAsistencia t WHERE t.EstadoAsistenciaCodigo = v.Codigo);
GO

/* ---- 20.17 CONCEPTOS DE JUSTIFICACION (punto 6 del encargo) ---- */
INSERT INTO Asistencia.ConceptoJustificacion (ConceptoJustificacionCodigo, ConceptoJustificacionNombre, ConceptoJustificacionRequiereDocumento, ConceptoJustificacionEsRemunerado, ConceptoJustificacionDescripcion)
SELECT v.Codigo, v.Nombre, v.ReqDoc, v.Remun, v.Descripcion
FROM (VALUES
    (N'DESCANSO_MED',  N'Descanso medico / CITT',            1, 1, N'Inasistencia por incapacidad temporal'),
    (N'ENFERMEDAD_FAM',N'Enfermedad de familiar directo',    1, 1, N'Atencion de familiar directo acreditada'),
    (N'DUELO',         N'Fallecimiento de familiar',         1, 1, N'Duelo por familiar directo'),
    (N'CITACION_JUD',  N'Citacion judicial o policial',      1, 1, N'Comparecencia ante autoridad'),
    (N'COMISION',      N'Comision de servicio no registrada',1, 1, N'Comision efectuada sin papeleta previa'),
    (N'CAPACITACION',  N'Capacitacion autorizada',           1, 1, N'Evento academico autorizado'),
    (N'EMERGENCIA',    N'Emergencia o caso fortuito',        0, 1, N'Situacion imprevista debidamente sustentada'),
    (N'FALLA_EQUIPO',  N'Falla del equipo de marcacion',     0, 1, N'La marcacion no se registro por causa tecnica'),
    (N'OLVIDO_MARCA',  N'Omision involuntaria de marcacion', 0, 1, N'El trabajador asistio pero no marco'),
    (N'HUELGA',        N'Paralizacion o huelga',             1, 0, N'Inasistencia por medida de fuerza'),
    (N'OTRO',          N'Otro motivo',                       1, 0, N'Requiere evaluacion del responsable')
) AS v(Codigo, Nombre, ReqDoc, Remun, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Asistencia.ConceptoJustificacion t WHERE t.ConceptoJustificacionCodigo = v.Codigo);
GO

/* ---- 20.18 TIPOS DE PAPELETA (punto 7 del encargo) ----
   Absorbe el antiguo catalogo Solicitudes.TipoPermiso. */
INSERT INTO Solicitudes.TipoPapeleta (TipoPapeletaCodigo, TipoPapeletaNombre, TipoPapeletaEsDescontable, TipoPapeletaRequiereSustento, TipoPapeletaEsCompensable, TipoPapeletaDescripcion)
SELECT v.Codigo, v.Nombre, v.Desc_, v.Sust, v.Comp, v.Descripcion
FROM (VALUES
    (N'COMISION',    N'Comision de servicio',   0, 1, 0, N'Salida por encargo institucional'),
    (N'PERM_OFICIAL',N'Permiso oficial',        0, 1, 0, N'Permiso por razones de servicio'),
    (N'PERM_PARTIC', N'Permiso particular',     1, 0, 1, N'Permiso por asunto personal, compensable'),
    (N'PERM_SALUD',  N'Permiso por salud',      0, 1, 0, N'Atencion medica del trabajador'),
    (N'LACTANCIA',   N'Permiso por lactancia',  0, 1, 0, N'Hora de lactancia materna'),
    (N'ONOMASTICO',  N'Dia de onomastico',      0, 0, 0, N'Descanso por cumpleanos'),
    (N'CAPACITACION',N'Capacitacion',           0, 1, 0, N'Asistencia a evento academico'),
    (N'SINDICAL',    N'Licencia sindical',      0, 1, 0, N'Permiso por funcion sindical'),
    (N'ESTUDIOS',    N'Permiso por estudios',   1, 1, 1, N'Permiso por motivos academicos')
) AS v(Codigo, Nombre, Desc_, Sust, Comp, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Solicitudes.TipoPapeleta t WHERE t.TipoPapeletaCodigo = v.Codigo);
GO

/* ---- 20.19 Motivos de papeleta (segundo nivel, cuelgan de su tipo) ---- */
INSERT INTO Solicitudes.MotivoPapeleta (TipoPapeletaId, MotivoPapeletaCodigo, MotivoPapeletaNombre)
SELECT tp.TipoPapeletaId, v.Codigo, v.Nombre
FROM (VALUES
    (N'COMISION',    N'COM_REUNION',   N'Reunion o coordinacion institucional'),
    (N'COMISION',    N'COM_TRAMITE',   N'Tramite documentario en sede'),
    (N'COMISION',    N'COM_CAMPANA',   N'Campana o actividad extramural'),
    (N'PERM_PARTIC', N'PAR_PERSONAL',  N'Asunto personal'),
    (N'PERM_PARTIC', N'PAR_FAMILIAR',  N'Asunto familiar'),
    (N'PERM_SALUD',  N'SAL_CONSULTA',  N'Consulta medica'),
    (N'PERM_SALUD',  N'SAL_EXAMEN',    N'Examen auxiliar o laboratorio'),
    (N'LACTANCIA',   N'LAC_HORA',      N'Hora de lactancia'),
    (N'CAPACITACION',N'CAP_CURSO',     N'Curso o taller'),
    (N'CAPACITACION',N'CAP_CONGRESO',  N'Congreso o jornada cientifica')
) AS v(TipoCodigo, Codigo, Nombre)
INNER JOIN Solicitudes.TipoPapeleta tp ON tp.TipoPapeletaCodigo = v.TipoCodigo
WHERE NOT EXISTS (SELECT 1 FROM Solicitudes.MotivoPapeleta m WHERE m.MotivoPapeletaCodigo = v.Codigo);
GO

/* ---- 20.20 Tipos de licencia ---- */
INSERT INTO Solicitudes.TipoLicencia (TipoLicenciaCodigo, TipoLicenciaNombre, TipoLicenciaConGoce, TipoLicenciaMaximoDias)
SELECT v.Codigo, v.Nombre, v.ConGoce, v.MaxDias
FROM (VALUES
    (N'MATERNIDAD',  N'Licencia por maternidad',            1, 98),
    (N'PATERNIDAD',  N'Licencia por paternidad',            1, 10),
    (N'ENFERMEDAD',  N'Licencia por enfermedad',            1, NULL),
    (N'FAMILIAR',    N'Licencia por familiar grave',        1, 7),
    (N'FALLECIMIENTO',N'Licencia por fallecimiento',        1, 5),
    (N'CAPACITACION',N'Licencia por capacitacion oficial',  1, NULL),
    (N'SIN_GOCE',    N'Licencia sin goce de haber',         0, NULL),
    (N'ESTUDIOS',    N'Licencia por estudios',              0, NULL),
    (N'REPRESENT',   N'Licencia por representacion',        1, NULL)
) AS v(Codigo, Nombre, ConGoce, MaxDias)
WHERE NOT EXISTS (SELECT 1 FROM Solicitudes.TipoLicencia t WHERE t.TipoLicenciaCodigo = v.Codigo);
GO

/* ---- 20.21 Compensaciones y descuentos ---- */
INSERT INTO Compensaciones.TipoCompensacion (TipoCompensacionCodigo, TipoCompensacionNombre)
SELECT v.Codigo, v.Nombre
FROM (VALUES
    (N'HORA_EXTRA',  N'Horas extras generadas'),
    (N'GUARDIA',     N'Compensacion por guardia'),
    (N'FERIADO',     N'Trabajo en dia no laborable'),
    (N'PERMISO_COMP',N'Devolucion de permiso particular')
) AS v(Codigo, Nombre)
WHERE NOT EXISTS (SELECT 1 FROM Compensaciones.TipoCompensacion t WHERE t.TipoCompensacionCodigo = v.Codigo);
GO

INSERT INTO Compensaciones.ConceptoDescuento (ConceptoDescuentoCodigo, ConceptoDescuentoNombre)
SELECT v.Codigo, v.Nombre
FROM (VALUES
    (N'DESC_FALTA',    N'Descuento por inasistencia injustificada'),
    (N'DESC_TARDANZA', N'Descuento por tardanza'),
    (N'DESC_SALIDA',   N'Descuento por salida anticipada'),
    (N'DESC_PERMISO',  N'Descuento por permiso particular no compensado'),
    (N'DESC_LICENCIA', N'Descuento por licencia sin goce')
) AS v(Codigo, Nombre)
WHERE NOT EXISTS (SELECT 1 FROM Compensaciones.ConceptoDescuento t WHERE t.ConceptoDescuentoCodigo = v.Codigo);
GO

/* ---- 20.22 Tipos de falta disciplinaria (renombrado) ---- */
INSERT INTO Disciplina.TipoFaltaDisciplinaria (TipoFaltaDisciplinariaCodigo, TipoFaltaDisciplinariaNombre, TipoFaltaDisciplinariaGravedad)
SELECT v.Codigo, v.Nombre, v.Gravedad
FROM (VALUES
    (N'INASIST_INJUST', N'Inasistencia injustificada reiterada', N'GRAVE'),
    (N'TARDANZA_REIT',  N'Tardanza reiterada',                   N'LEVE'),
    (N'ABANDONO',       N'Abandono del puesto de trabajo',       N'MUY_GRAVE'),
    (N'MARCA_TERCERO',  N'Marcacion por tercero',                N'MUY_GRAVE'),
    (N'INCUMPL_HORARIO',N'Incumplimiento de horario',            N'LEVE'),
    (N'NEGLIGENCIA',    N'Negligencia en el desempeno',          N'GRAVE')
) AS v(Codigo, Nombre, Gravedad)
WHERE NOT EXISTS (SELECT 1 FROM Disciplina.TipoFaltaDisciplinaria t WHERE t.TipoFaltaDisciplinariaCodigo = v.Codigo);
GO

/* ---- 20.23 Roles base ---- */
INSERT INTO Seguridad.Rol (RolCodigo, RolNombre, RolDescripcion)
SELECT v.Codigo, v.Nombre, v.Descripcion
FROM (VALUES
    (N'ADMIN',        N'Administrador del sistema',      N'Acceso total'),
    (N'RRHH_RED',     N'Recursos Humanos - Red',         N'Gestion de personal de toda la Red'),
    (N'JEFE_MICRORED',N'Jefe de Microred',               N'Acceso a los EESS de su Microred'),
    (N'RESP_EESS',    N'Responsable de EESS',            N'Gestion del personal de su establecimiento'),
    (N'PROGRAMADOR',  N'Responsable de programacion',    N'Elabora y remite la programacion de turnos'),
    (N'TRABAJADOR',   N'Trabajador',                     N'Consulta de su propia asistencia y solicitudes'),
    (N'PORTERIA',     N'Porteria',                       N'Registro de ocurrencias')
) AS v(Codigo, Nombre, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Seguridad.Rol t WHERE t.RolCodigo = v.Codigo);
GO

/* ---- 20.24 Parametros del sistema ---- */
INSERT INTO Configuracion.ParametroSistema (ParametroSistemaCodigo, ParametroSistemaValor, ParametroSistemaDescripcion)
SELECT v.Codigo, v.Valor, v.Descripcion
FROM (VALUES
    (N'ENTIDAD_NOMBRE',        N'Red de Salud Trujillo',  N'Nombre de la entidad'),
    (N'DOC_STORAGE_BASE',      N'/storage/documentos',    N'Ruta base del almacenamiento externo de documentos'),
    (N'DIAS_JUSTIFICAR_FALTA', N'3',                      N'Dias habiles para presentar la justificacion de una falta'),
    (N'DIAS_ANTICIPO_PROG',    N'5',                      N'Dias de anticipacion para remitir la programacion'),
    (N'TOLERANCIA_GLOBAL_MIN', N'5',                      N'Minutos de tolerancia por defecto')
) AS v(Codigo, Valor, Descripcion)
WHERE NOT EXISTS (SELECT 1 FROM Configuracion.ParametroSistema t WHERE t.ParametroSistemaCodigo = v.Codigo);
GO

/* ================================================================================
   21. VISTAS DE APOYO
   --------------------------------------------------------------------------------
   Resuelven las consultas jerarquicas que el modelo plano anterior no permitia.
   Son opcionales: el backend Laravel puede replicarlas con Eloquent.
   ================================================================================ */

/* Estructura completa: cada EESS con su Microred. Sin recursividad. */
IF OBJECT_ID(N'Organizacion.vw_Estructura', N'V') IS NOT NULL
    DROP VIEW Organizacion.vw_Estructura;
GO
CREATE VIEW Organizacion.vw_Estructura
AS
SELECT  m.MicroredId,
        m.MicroredCodigo,
        m.MicroredNombre,
        m.MicroredDistrito,
        e.EessId,
        e.EessCodigo,
        e.EessNombre,
        e.EessCodigoRenipres,
        e.EessCategoria,
        te.TipoEstablecimientoNombre,
        e.EessEstado
FROM Organizacion.Microred m
LEFT JOIN Organizacion.EstablecimientoSalud e  ON e.MicroredId = m.MicroredId
LEFT JOIN Organizacion.TipoEstablecimiento te  ON te.TipoEstablecimientoId = e.TipoEstablecimientoId;
GO

/* Cuantos establecimientos tiene cada Microred. */
IF OBJECT_ID(N'Organizacion.vw_ResumenMicrored', N'V') IS NOT NULL
    DROP VIEW Organizacion.vw_ResumenMicrored;
GO
CREATE VIEW Organizacion.vw_ResumenMicrored
AS
SELECT  m.MicroredId,
        m.MicroredNombre,
        m.MicroredDistrito,
        COUNT(e.EessId) AS TotalEstablecimientos,
        SUM(CASE WHEN e.EessEstado = 1 THEN 1 ELSE 0 END) AS EstablecimientosActivos
FROM Organizacion.Microred m
LEFT JOIN Organizacion.EstablecimientoSalud e ON e.MicroredId = m.MicroredId
GROUP BY m.MicroredId, m.MicroredNombre, m.MicroredDistrito;
GO

/* Responsable VIGENTE de cada EESS, con su trabajador y su cargo. */
IF OBJECT_ID(N'Organizacion.vw_ResponsableVigente', N'V') IS NOT NULL
    DROP VIEW Organizacion.vw_ResponsableVigente;
GO
CREATE VIEW Organizacion.vw_ResponsableVigente
AS
SELECT  re.ResponsableEessId,
        e.EessId,
        e.EessNombre,
        m.MicroredId,
        m.MicroredNombre,
        tr.TipoResponsabilidadCodigo,
        tr.TipoResponsabilidadNombre,
        t.TrabajadorId,
        t.TrabajadorNombreCompleto,
        t.TrabajadorNumeroDocumento,
        c.CargoNombre,
        re.ResponsableEessFechaInicio
FROM Organizacion.ResponsableEess re
INNER JOIN Organizacion.EstablecimientoSalud e ON e.EessId = re.EessId
INNER JOIN Organizacion.Microred             m ON m.MicroredId = e.MicroredId
INNER JOIN Organizacion.TipoResponsabilidad tr ON tr.TipoResponsabilidadId = re.TipoResponsabilidadId
INNER JOIN Personal.VinculoLaboral          vl ON vl.VinculoLaboralId = re.VinculoLaboralId
INNER JOIN Personal.Trabajador              t  ON t.TrabajadorId = vl.TrabajadorId
INNER JOIN Personal.Cargo                   c  ON c.CargoId = vl.CargoId
WHERE re.ResponsableEessFechaFin IS NULL
  AND re.ResponsableEessEstado = 1;
GO

/* Ficha laboral consolidada del trabajador: condicion, AIRHSP, cargo, profesion,
   colegiatura principal, EESS, Microred y horario vigente. */
IF OBJECT_ID(N'Personal.vw_FichaTrabajador', N'V') IS NOT NULL
    DROP VIEW Personal.vw_FichaTrabajador;
GO
CREATE VIEW Personal.vw_FichaTrabajador
AS
SELECT  t.TrabajadorId,
        t.TrabajadorNumeroDocumento,
        t.TrabajadorNombreCompleto,
        vl.VinculoLaboralId,
        vl.VinculoLaboralCodigoAirhsp,
        vl.VinculoLaboralNumeroPlaza,
        rl.RegimenLaboralNombre,
        cl.CondicionLaboralNombre,
        c.CargoNombre,
        go.GrupoOcupacionalNombre,
        pr.ProfesionNombre,
        ct.ColegiaturaTipoCodigo,
        col.ColegiaturaNumero,
        col.ColegiaturaEsHabilitado,
        eess.EessId,
        eess.EessNombre,
        mr.MicroredId,
        mr.MicroredNombre,
        h.HorarioId,
        h.HorarioNombre
FROM Personal.Trabajador t
INNER JOIN Personal.VinculoLaboral      vl   ON vl.TrabajadorId = t.TrabajadorId AND vl.VinculoLaboralEstado = 1
INNER JOIN Personal.RegimenLaboral      rl   ON rl.RegimenLaboralId = vl.RegimenLaboralId
INNER JOIN Personal.CondicionLaboral    cl   ON cl.CondicionLaboralId = vl.CondicionLaboralId
INNER JOIN Personal.Cargo               c    ON c.CargoId = vl.CargoId
INNER JOIN Personal.GrupoOcupacional    go   ON go.GrupoOcupacionalId = c.GrupoOcupacionalId
LEFT  JOIN Personal.Profesion           pr   ON pr.ProfesionId = t.ProfesionId
LEFT  JOIN Personal.Colegiatura         col  ON col.TrabajadorId = t.TrabajadorId AND col.ColegiaturaEsPrincipal = 1 AND col.ColegiaturaEstado = 1
LEFT  JOIN Personal.ColegiaturaTipo     ct   ON ct.ColegiaturaTipoId = col.ColegiaturaTipoId
INNER JOIN Organizacion.EstablecimientoSalud eess ON eess.EessId = vl.EessId
INNER JOIN Organizacion.Microred             mr   ON mr.MicroredId = eess.MicroredId
LEFT  JOIN Personal.AsignacionHorario   ah   ON ah.VinculoLaboralId = vl.VinculoLaboralId AND ah.AsignacionHorarioFechaFin IS NULL AND ah.AsignacionHorarioEstado = 1
LEFT  JOIN Configuracion.Horario        h    ON h.HorarioId = ah.HorarioId;
GO

/* Turnos programados por EESS, listos para el tablero de programacion global. */
IF OBJECT_ID(N'Programacion.vw_TurnosPorEess', N'V') IS NOT NULL
    DROP VIEW Programacion.vw_TurnosPorEess;
GO
CREATE VIEW Programacion.vw_TurnosPorEess
AS
SELECT  pp.ProgramacionPeriodoId,
        pp.EessId,
        e.EessNombre,
        m.MicroredId,
        m.MicroredNombre,
        pp.ProgramacionPeriodoAnio,
        pp.ProgramacionPeriodoMes,
        pp.ProgramacionPeriodoEstado,
        pt.ProgramacionTrabajadorId,
        vl.VinculoLaboralId,
        t.TrabajadorId,
        t.TrabajadorNombreCompleto,
        tp.TurnoProgramadoId,
        tp.TurnoProgramadoFecha,
        tu.TurnoCodigo,
        tu.TurnoNombre,
        ISNULL(tp.TurnoProgramadoHoraEntrada, tu.TurnoHoraEntrada) AS HoraEntrada,
        ISNULL(tp.TurnoProgramadoHoraSalida,  tu.TurnoHoraSalida)  AS HoraSalida,
        tu.TurnoCruzaMedianoche,
        tp.TurnoProgramadoEstado
FROM Programacion.ProgramacionPeriodo    pp
INNER JOIN Organizacion.EstablecimientoSalud e ON e.EessId = pp.EessId
INNER JOIN Organizacion.Microred             m ON m.MicroredId = e.MicroredId
INNER JOIN Programacion.ProgramacionTrabajador pt ON pt.ProgramacionPeriodoId = pp.ProgramacionPeriodoId
INNER JOIN Personal.VinculoLaboral       vl ON vl.VinculoLaboralId = pt.VinculoLaboralId
INNER JOIN Personal.Trabajador           t  ON t.TrabajadorId = vl.TrabajadorId
INNER JOIN Programacion.TurnoProgramado  tp ON tp.ProgramacionTrabajadorId = pt.ProgramacionTrabajadorId
INNER JOIN Configuracion.Turno           tu ON tu.TurnoId = tp.TurnoId;
GO

/* Faltas y su situacion de justificacion. */
IF OBJECT_ID(N'Asistencia.vw_FaltasJustificacion', N'V') IS NOT NULL
    DROP VIEW Asistencia.vw_FaltasJustificacion;
GO
CREATE VIEW Asistencia.vw_FaltasJustificacion
AS
SELECT  ad.AsistenciaDiariaId,
        ad.AsistenciaDiariaFecha,
        vl.VinculoLaboralId,
        t.TrabajadorId,
        t.TrabajadorNombreCompleto,
        e.EessId,
        e.EessNombre,
        m.MicroredNombre,
        ea.EstadoAsistenciaCodigo,
        jf.JustificacionFaltaId,
        cj.ConceptoJustificacionNombre,
        jf.JustificacionFaltaEstado,
        ds.DocumentoSustentoRuta,
        CASE WHEN jf.JustificacionFaltaId IS NULL THEN N'SIN_JUSTIFICAR'
             WHEN jf.JustificacionFaltaEstado = N'APROBADO' THEN N'JUSTIFICADA'
             ELSE jf.JustificacionFaltaEstado END AS SituacionJustificacion
FROM Asistencia.AsistenciaDiaria         ad
INNER JOIN Asistencia.EstadoAsistencia   ea ON ea.EstadoAsistenciaId = ad.EstadoAsistenciaId
INNER JOIN Personal.VinculoLaboral       vl ON vl.VinculoLaboralId = ad.VinculoLaboralId
INNER JOIN Personal.Trabajador           t  ON t.TrabajadorId = vl.TrabajadorId
INNER JOIN Organizacion.EstablecimientoSalud e ON e.EessId = vl.EessId
INNER JOIN Organizacion.Microred             m ON m.MicroredId = e.MicroredId
LEFT  JOIN Asistencia.JustificacionFalta jf ON jf.JustificacionFaltaId = ad.JustificacionFaltaId
LEFT  JOIN Asistencia.ConceptoJustificacion cj ON cj.ConceptoJustificacionId = jf.ConceptoJustificacionId
LEFT  JOIN Soporte.DocumentoSustento     ds ON ds.DocumentoSustentoId = jf.DocumentoSustentoId
WHERE ea.EstadoAsistenciaEsFalta = 1;
GO
