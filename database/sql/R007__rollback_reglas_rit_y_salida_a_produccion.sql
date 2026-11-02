/* R007: reversa de V007. Borra los registros de las tablas nuevas. */
DROP TABLE IF EXISTS Disciplina.ReconocimientoServidor;
DROP TABLE IF EXISTS Biometria.ContingenciaMarcacion;
DROP TABLE IF EXISTS Asistencia.FacilidadHoraria;
DROP TABLE IF EXISTS Asistencia.AutorizacionIngreso;
DROP TABLE IF EXISTS Asistencia.ToleranciaExtraordinaria;
DROP TABLE IF EXISTS Asistencia.AvisoAusencia;
DROP TABLE IF EXISTS Personal.DesplazamientoTemporal;
DROP TABLE IF EXISTS Personal.EntregaCargo;
DROP TABLE IF EXISTS Personal.LegajoDocumento;
DROP TABLE IF EXISTS Personal.Fotocheck;
GO
ALTER TABLE Disciplina.ExpedientePad DROP CONSTRAINT IF EXISTS DF_ExpedientePadMedidaCautelar;
ALTER TABLE Disciplina.ExpedientePad DROP COLUMN IF EXISTS ExpedientePadMedidaCautelar;
ALTER TABLE Vacaciones.GoceVacacional DROP CONSTRAINT IF EXISTS DF_GoceVacacionalEsAdelanto, DF_GoceVacacionalFechaSolicitud;
ALTER TABLE Vacaciones.GoceVacacional DROP COLUMN IF EXISTS GoceVacacionalEsAdelanto, GoceVacacionalFechaSolicitud;
ALTER TABLE Programacion.ProgramacionPeriodo DROP CONSTRAINT IF EXISTS DF_ProgramacionPeriodoReprogramaciones;
ALTER TABLE Programacion.ProgramacionPeriodo DROP COLUMN IF EXISTS ProgramacionPeriodoReprogramaciones, ProgramacionPeriodoMotivoTardio;
ALTER TABLE Consolidacion.ConsolidadoAsistencia DROP CONSTRAINT IF EXISTS DF_ConsolidadoAsistenciaMinutosSinGoce;
ALTER TABLE Consolidacion.ConsolidadoAsistencia DROP COLUMN IF EXISTS ConsolidadoAsistenciaMinutosSinGoce;
ALTER TABLE Asistencia.AsistenciaDiaria DROP CONSTRAINT IF EXISTS DF_AsistenciaDiariaMinutosSinGoce;
ALTER TABLE Asistencia.AsistenciaDiaria DROP COLUMN IF EXISTS AsistenciaDiariaMinutosSinGoce;
ALTER TABLE Asistencia.JustificacionFalta DROP CONSTRAINT IF EXISTS DF_JustificacionFaltaEsExtemporanea;
ALTER TABLE Asistencia.JustificacionFalta DROP COLUMN IF EXISTS JustificacionFaltaEsExtemporanea;
ALTER TABLE Solicitudes.DescansoMedico DROP CONSTRAINT IF EXISTS DF_DescansoMedicoEsExtemporaneo;
ALTER TABLE Solicitudes.DescansoMedico DROP COLUMN IF EXISTS DescansoMedicoEsExtemporaneo;
ALTER TABLE Solicitudes.Papeleta DROP CONSTRAINT IF EXISTS FK_Papeleta_VistoBueno, DF_PapeletaEsUrgente, DF_PapeletaPartoMultiple;
ALTER TABLE Solicitudes.Papeleta DROP COLUMN IF EXISTS PapeletaVistoBuenoPor, PapeletaFechaVistoBueno, PapeletaEsUrgente, PapeletaPartoMultiple, PapeletaFechaNacimientoHijo, PapeletaUnidadDestino, PapeletaConformeDestino;
ALTER TABLE Solicitudes.Licencia DROP CONSTRAINT IF EXISTS FK_Licencia_VistoBueno, DF_LicenciaEsExtemporanea;
ALTER TABLE Solicitudes.Licencia DROP COLUMN IF EXISTS LicenciaCasoEspecial, LicenciaVistoBuenoPor, LicenciaFechaVistoBueno, LicenciaEsExtemporanea;
ALTER TABLE Solicitudes.TipoLicencia DROP CONSTRAINT IF EXISTS DF_TipoLicenciaACuentaVacaciones, DF_TipoLicenciaExigeAnticipacion;
ALTER TABLE Solicitudes.TipoLicencia DROP COLUMN IF EXISTS TipoLicenciaSexo, TipoLicenciaMaximoDiasCas, TipoLicenciaMaximoDiasAnio, TipoLicenciaACuentaVacaciones, TipoLicenciaExigeAnticipacion;
ALTER TABLE Personal.VinculoLaboral DROP COLUMN IF EXISTS VinculoLaboralIngresoMensual, VinculoLaboralCausaCese, VinculoLaboralFechaRenuncia;
GO
