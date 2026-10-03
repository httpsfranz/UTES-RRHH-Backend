<?php

namespace Database\Seeders;

use App\Models\Consolidacion\PeriodoAsistencia;
use App\Services\ConsolidadoAsistenciaService;
use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba de Nivel 3 (asistencia, solicitudes, programacion, seguridad, consolidacion...). Corre al final de
 * DatosPruebaSeeder: necesita los vinculos, usuarios, documentos, EESS y catalogos ya sembrados. Es idempotente
 * (cada tabla se siembra solo si esta vacia) y deterministico (fechas fijas, sin aleatorios).
 *
 * Fechas: el periodo de asistencia 2026-09 esta EN_PROCESO y 2026-10 ABIERTO; Ene-Ago estan CERRADOS (no admiten
 * cambios), asi que la asistencia de prueba vive en septiembre. "Hoy" para los datos de prueba es 2026-10-02.
 */
class DatosPruebaNivel3Seeder extends Seeder
{
    use SiembraCatalogos;

    public function run(): void
    {
        // Lote A: asistencia y soporte.
        $this->cargasDeAsistenciaManual();
        $this->marcaciones();
        $this->justificacionesYAsistenciaDiaria();
        $this->notificaciones();

        // Lote B: solicitudes.
        $this->papeletas();
        $this->licencias();
        $this->descansosMedicosYConstataciones();

        // Lote C: programacion, personal, organizacion y seguridad.
        $this->programaciones();
        $this->informesDeGuardiaComunitaria();
        $this->asignacionesDeHorario();
        $this->responsablesDeEess();
        $this->rolesYAmbitosDeUsuario();

        // Lote D: consolidacion, compensaciones, vacaciones y disciplina.
        $this->consolidados();
        $this->compensacionesHorarias();
        $this->periodosVacacionales();
        $this->expedientesPad();
        $this->supervisionesInopinadas();
    }

    private function cargasDeAsistenciaManual(): void
    {
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $documento = (int) DB::table('Soporte.DocumentoSustento')->where('DocumentoSustentoNombre', 'foto-constatación-0005.jpg')->value('DocumentoSustentoId');

        // [usuario, eess|null, documento?, fecha, archivo, registros|null, observacion|null, estado]
        $cargas = [
            ['rvargas', 'EESS-LA-01', true, '2026-09-30 16:00:00', 'parte-diario-la-0930.xlsx', 4, 'Parte diario del C.S. Laredo (sin reloj biométrico)', 'PROCESADO'],
            ['gdangelo', 'SEDE-RRHH', false, '2026-09-30 17:30:00', 'parte-sede-0925.xlsx', null, 'Faltan las firmas de la jefatura en dos hojas', 'OBSERVADO'],
            ['rvargas', 'EESS-SA-01', false, '2026-09-02 09:00:00', 'parte-sa-0901.xlsx', 2, 'Se cargó el archivo equivocado', 'ANULADO'],
            ['gdangelo', null, false, '2026-10-01 08:45:00', 'parte-red-1001.xlsx', null, null, 'REGISTRADO'],
        ];
        $this->sembrarSiVacia('Asistencia.CargaAsistenciaManual', array_map(fn (array $c) => [
            'UsuarioId' => $usuarios[$c[0]],
            'EessId' => $c[1] === null ? null : $eess[$c[1]],
            'DocumentoSustentoId' => $c[2] ? $documento : null,
            'CargaAsistenciaManualFecha' => $c[3],
            'CargaAsistenciaManualNombreArchivo' => $c[4],
            'CargaAsistenciaManualRegistros' => $c[5],
            'CargaAsistenciaManualObservacion' => $c[6],
            'CargaAsistenciaManualEstado' => $c[7],
        ], $cargas));
    }

    private function marcaciones(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $metodos = $this->ids('Biometria.MetodoMarcacion', 'MetodoMarcacionCodigo', 'MetodoMarcacionId');
        $dispositivos = $this->ids('Biometria.DispositivoMarcacion', 'DispositivoMarcacionCodigo', 'DispositivoMarcacionId');
        $cargas = DB::table('Asistencia.CargaAsistenciaManual')->orderBy('CargaAsistenciaManualId')->pluck('CargaAsistenciaManualId')->map(fn ($i) => (int) $i)->all();
        $trabajadorDe = DB::table('Personal.VinculoLaboral')->pluck('TrabajadorId', 'VinculoLaboralCodigo')->all();
        $plantilla = fn (string $codigoVinculo, string $tipo) => DB::table('Biometria.PlantillaBiometrica')
            ->where('TrabajadorId', $trabajadorDe[$codigoVinculo])->where('PlantillaBiometricaTipo', $tipo)->where('PlantillaBiometricaEstado', 1)
            ->value('PlantillaBiometricaId');

        // [vinculo, fecha y hora, tipo, metodo, dispositivo|null, plantilla(tipo)|null, carga(indice)|null, origen, observacion|null, valida]
        $marcaciones = [
            // Reconocimiento facial (la forma de registro del RIT, Art. 21).
            ['VL-0001', '2026-09-28 07:28:00', 'ENTRADA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0001', '2026-09-28 13:34:00', 'SALIDA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0001', '2026-09-29 07:31:00', 'ENTRADA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0001', '2026-09-29 10:05:00', 'SALIDA_PAPELETA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', 'Comisión de servicio', 1],
            ['VL-0001', '2026-09-29 11:20:00', 'RETORNO_PAPELETA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0001', '2026-09-29 13:30:00', 'SALIDA', 'ROSTRO', 'DISP-LE-02', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0003', '2026-09-28 07:29:00', 'ENTRADA', 'ROSTRO', 'DISP-FM-01', 'ROSTRO', null, 'DISPOSITIVO', null, 1],
            ['VL-0004', '2026-09-28 07:41:00', 'ENTRADA', 'ROSTRO', null, 'ROSTRO', null, 'DISPOSITIVO', 'Tardanza de 11 minutos', 1],
            // Huella: el medico tiene autorizacion de metodo vigente desde 2026-01-01.
            ['VL-0002', '2026-09-28 07:25:00', 'ENTRADA', 'HUELLA', 'DISP-EP-01', 'HUELLA', null, 'DISPOSITIVO', null, 1],
            ['VL-0002', '2026-09-28 13:40:00', 'SALIDA', 'HUELLA', 'DISP-EP-01', 'HUELLA', null, 'DISPOSITIVO', null, 1],
            // Registro manual autorizado (psicologa y vigilante tienen autorizacion MANUAL vigente).
            ['VL-0005', '2026-09-29 07:35:00', 'ENTRADA', 'MANUAL', null, null, null, 'MANUAL', 'Reloj de la sede en mantenimiento', 1],
            ['VL-0010', '2026-09-29 18:58:00', 'ENTRADA', 'MANUAL', null, null, null, 'MANUAL', null, 1],
            // Parte diario del C.S. Laredo (carga 1, procesada): el medico destacado.
            ['VL-0013', '2026-09-29 07:32:00', 'ENTRADA', 'MANUAL', null, null, 0, 'CARGA_MANUAL', null, 1],
            ['VL-0013', '2026-09-29 13:31:00', 'SALIDA', 'MANUAL', null, null, 0, 'CARGA_MANUAL', null, 1],
            ['VL-0013', '2026-09-30 07:30:00', 'ENTRADA', 'MANUAL', null, null, 0, 'CARGA_MANUAL', null, 1],
            ['VL-0013', '2026-09-30 13:35:00', 'SALIDA', 'MANUAL', null, null, 0, 'CARGA_MANUAL', null, 1],
            // Carga anulada (carga 3): sus marcaciones quedaron invalidas.
            ['VL-0014', '2026-09-01 07:30:00', 'ENTRADA', 'MANUAL', null, null, 2, 'CARGA_MANUAL', null, 0],
            ['VL-0014', '2026-09-01 13:30:00', 'SALIDA', 'MANUAL', null, null, 2, 'CARGA_MANUAL', null, 0],
            // Marcacion invalidada por el responsable.
            ['VL-0009', '2026-09-28 07:30:00', 'ENTRADA', 'ROSTRO', null, null, null, 'DISPOSITIVO', 'Marcación de prueba del técnico del reloj', 0],
            // Desde la app movil (campana extramural).
            ['VL-0007', '2026-09-27 08:05:00', 'ENTRADA', 'APP', 'DISP-MOV-01', null, null, 'APP', 'Campaña de vacunación en el AA.HH. Alto Trujillo', 1],
        ];
        $geo = ['VL-0007' => '-8.1116,-79.0288'];

        // APP y TARJETA/CLAVE no tienen autorizacion sembrada: la marcacion de la app se siembra sin la regla (el seeder inserta directo).
        $this->sembrarSiVacia('Asistencia.Marcacion', array_map(fn (array $m) => [
            'VinculoLaboralId' => $vinculos[$m[0]],
            'MetodoMarcacionId' => $metodos[$m[3]],
            'DispositivoMarcacionId' => $m[4] === null ? null : $dispositivos[$m[4]],
            'PlantillaBiometricaId' => $m[5] === null ? null : $plantilla($m[0], $m[5]),
            'CargaAsistenciaManualId' => $m[6] === null ? null : ($cargas[$m[6]] ?? null),
            'MarcacionFechaHora' => $m[1],
            'MarcacionTipo' => $m[2],
            'MarcacionGeolocalizacion' => $m[3] === 'APP' ? $geo[$m[0]] : null,
            'MarcacionObservacion' => $m[8],
            'MarcacionOrigen' => $m[7],
            'MarcacionEsValida' => $m[9],
        ], $marcaciones));
    }

    private function justificacionesYAsistenciaDiaria(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $conceptos = $this->ids('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionCodigo', 'ConceptoJustificacionId');
        $estados = $this->ids('Asistencia.EstadoAsistencia', 'EstadoAsistenciaCodigo', 'EstadoAsistenciaId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [vinculo, concepto, documento|null, registra, inicio, fin, n.o documento|null, observacion|null, estado, resuelve|null, fecha resolucion|null, motivo rechazo|null]
        $justificaciones = [
            ['VL-0006', 'EMERGENCIA', null, 'rvargas', '2026-09-28', '2026-09-29', null, 'Emergencia familiar; presentará la constancia', 'PENDIENTE', null, null, null],
            ['VL-0009', 'DESCANSO_MED', 'certificado-medico-0001.pdf', 'rvargas', '2026-09-29', '2026-09-29', 'CITT-4471', null, 'APROBADO', 'pgutierrez', '2026-09-30 10:00:00', null],
            ['VL-0012', 'FALLA_EQUIPO', null, 'rvargas', '2026-09-15', '2026-09-16', null, 'El lector no registró la salida', 'RECHAZADO', 'pgutierrez', '2026-09-17 09:30:00', 'El reloj funcionó con normalidad esos días según el reporte técnico'],
            ['VL-0011', 'OLVIDO_MARCA', null, 'gdangelo', '2026-09-30', '2026-09-30', null, 'Olvidó marcar la salida', 'PENDIENTE', null, null, null],
            ['VL-0004', 'DUELO', 'acta-duelo-0006.pdf', 'rvargas', '2026-09-10', '2026-09-11', null, 'Registrada dos veces', 'ANULADO', null, null, null],
            ['VL-0007', 'EMERGENCIA', null, 'rvargas', '2026-08-12', '2026-08-12', null, null, 'APROBADO', 'pgutierrez', '2026-08-14 11:00:00', null],
        ];
        $this->sembrarSiVacia('Asistencia.JustificacionFalta', array_map(fn (array $j) => [
            'VinculoLaboralId' => $vinculos[$j[0]],
            'ConceptoJustificacionId' => $conceptos[$j[1]],
            'DocumentoSustentoId' => $j[2] === null ? null : $documentos[$j[2]],
            'UsuarioRegistroId' => $usuarios[$j[3]],
            'UsuarioResolucionId' => $j[9] === null ? null : $usuarios[$j[9]],
            'JustificacionFaltaFechaInicio' => $j[4],
            'JustificacionFaltaFechaFin' => $j[5],
            'JustificacionFaltaDocumentoNumero' => $j[6],
            'JustificacionFaltaObservacion' => $j[7],
            'JustificacionFaltaMotivoRechazo' => $j[11],
            'JustificacionFaltaFechaRegistro' => date('Y-m-d H:i:s', strtotime($j[4].' 12:00:00 +1 day')),
            'JustificacionFaltaFechaResolucion' => $j[10],
            'JustificacionFaltaEstado' => $j[8],
        ], $justificaciones));

        $justificacionAprobada = (int) DB::table('Asistencia.JustificacionFalta')
            ->where(['VinculoLaboralId' => $vinculos['VL-0009'], 'JustificacionFaltaEstado' => 'APROBADO'])->value('JustificacionFaltaId');

        // [vinculo, fecha, estado, entrada|null, salida|null, tardanza, falta, extra, trabajados, justificacion?, observacion|null]
        $dia = fn (string $v, string $f, string $e, ?string $in, ?string $out, int $tar, int $fal, int $ext, int $tra, bool $just = false, ?string $obs = null) => [$v, $f, $e, $in, $out, $tar, $fal, $ext, $tra, $just, $obs];
        $asistencias = [
            $dia('VL-0001', '2026-09-28', 'ASISTIO', '2026-09-28 07:28:00', '2026-09-28 13:34:00', 0, 0, 4, 366),
            $dia('VL-0001', '2026-09-29', 'PAPELETA', '2026-09-29 07:31:00', '2026-09-29 13:30:00', 0, 0, 0, 359, false, 'Salió con papeleta de 10:05 a 11:20'),
            $dia('VL-0002', '2026-09-28', 'ASISTIO', '2026-09-28 07:25:00', '2026-09-28 13:40:00', 0, 0, 10, 375),
            $dia('VL-0003', '2026-09-28', 'ASISTIO', '2026-09-28 07:29:00', null, 0, 0, 0, 0, false, 'Sin marcación de salida: se revisa con el responsable'),
            $dia('VL-0004', '2026-09-28', 'TARDANZA', '2026-09-28 07:41:00', '2026-09-28 13:35:00', 11, 20, 0, 354, false, 'Tardanza de 11 minutos: descuenta 20 (RIT, Art. 22)'),
            $dia('VL-0005', '2026-09-29', 'ASISTIO', '2026-09-29 07:35:00', '2026-09-29 15:32:00', 5, 0, 0, 477, false, 'Dentro de la tolerancia'),
            $dia('VL-0006', '2026-09-28', 'FALTA', null, null, 0, 360, 0, 0, false, 'Pendiente de justificación'),
            $dia('VL-0006', '2026-09-29', 'FALTA', null, null, 0, 360, 0, 0, false, 'Pendiente de justificación'),
            $dia('VL-0008', '2026-09-29', 'SALIDA_ANTIC', '2026-09-29 07:28:00', '2026-09-29 11:00:00', 0, 270, 0, 212, false, 'Se retiró sin papeleta (ver ocurrencia de portería)'),
            $dia('VL-0009', '2026-09-29', 'FALTA_JUST', null, null, 0, 0, 0, 0, true, 'Descanso médico con CITT-4471'),
            $dia('VL-0011', '2026-09-30', 'OMISION_MARCA', '2026-09-30 07:33:00', null, 0, 0, 0, 0, false, 'Falta la marcación de salida'),
            $dia('VL-0012', '2026-09-28', 'DESCANSO', null, null, 0, 0, 0, 0),
            $dia('VL-0013', '2026-09-29', 'ASISTIO', '2026-09-29 07:32:00', '2026-09-29 13:31:00', 2, 0, 0, 359, false, 'Parte diario manual'),
            $dia('VL-0007', '2026-09-27', 'ASISTIO', '2026-09-27 08:05:00', null, 0, 0, 0, 0, false, 'Campaña extramural (aplicativo móvil)'),
        ];
        $this->sembrarSiVacia('Asistencia.AsistenciaDiaria', array_map(fn (array $a) => [
            'VinculoLaboralId' => $vinculos[$a[0]],
            'EstadoAsistenciaId' => $estados[$a[2]],
            'JustificacionFaltaId' => $a[9] ? $justificacionAprobada : null,
            'AsistenciaDiariaFecha' => $a[1],
            'AsistenciaDiariaHoraEntrada' => $a[3],
            'AsistenciaDiariaHoraSalida' => $a[4],
            'AsistenciaDiariaMinutosTardanza' => $a[5],
            'AsistenciaDiariaMinutosFalta' => $a[6],
            'AsistenciaDiariaMinutosExtra' => $a[7],
            'AsistenciaDiariaMinutosTrabajados' => $a[8],
            'AsistenciaDiariaObservacion' => $a[10],
            'AsistenciaDiariaFechaProceso' => '2026-09-30 22:00:00',
        ], $asistencias));
    }

    private function notificaciones(): void
    {
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');

        // [usuario, tipo, titulo, mensaje, enlace|null, fecha, leida]
        $avisos = [
            ['rvargas', 'JUSTIFICACION_APROBADA', 'Justificación aprobada', 'Tu justificación de faltas fue aprobada.', '/asistencia/justificacion-faltas', '2026-09-30 10:00:05', 1],
            ['rvargas', 'JUSTIFICACION_RECHAZADA', 'Justificación rechazada', 'Tu justificación de faltas fue rechazada: El reloj funcionó con normalidad esos días según el reporte técnico', '/asistencia/justificacion-faltas', '2026-09-17 09:30:05', 1],
            ['rvargas', 'CARGA_OBSERVADA', 'Carga de asistencia observada', 'La carga "parte-sede-0925.xlsx" tiene observaciones.', '/asistencia/carga-manual', '2026-10-01 09:00:00', 0],
            ['gdangelo', 'GENERAL', 'Cierre de asistencia de setiembre', 'El período de asistencia de setiembre se cerrará el 5 de octubre: revisa las justificaciones pendientes.', '/asistencia/justificacion-faltas', '2026-10-01 08:00:00', 0],
            ['pgutierrez', 'GENERAL', 'Programación de octubre', 'Hay programaciones de octubre pendientes de publicar.', '/programacion/periodos', '2026-10-01 08:05:00', 0],
            ['mquispe', 'GENERAL', 'Bienvenida', 'Tu cuenta fue creada. Cambia tu contraseña en el primer ingreso.', null, '2026-09-02 09:00:10', 1],
        ];
        $this->sembrarSiVacia('Soporte.Notificacion', array_map(fn (array $a) => [
            'UsuarioId' => $usuarios[$a[0]],
            'NotificacionTipo' => $a[1],
            'NotificacionTitulo' => $a[2],
            'NotificacionMensaje' => $a[3],
            'NotificacionEnlace' => $a[4],
            'NotificacionFecha' => $a[5],
            'NotificacionLeida' => $a[6],
        ], $avisos));
    }

    private function papeletas(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $tipos = $this->ids('Solicitudes.TipoPapeleta', 'TipoPapeletaCodigo', 'TipoPapeletaId');
        $motivos = $this->ids('Solicitudes.MotivoPapeleta', 'MotivoPapeletaCodigo', 'MotivoPapeletaId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [vinculo, tipo, motivo|null, documento|null, numero|null, fecha, salida|null, retorno|null, dia completo, minutos|null,
        //  detalle|null, observacion|null, estado, autoriza|null]
        $papeletas = [
            ['VL-0001', 'COMISION', 'COM_REUNION', null, 'PS-0123', '2026-09-29', '10:05:00', '11:20:00', 0, 75, 'Reunión de coordinación en la sede', null, 'APROBADO', 'pgutierrez'],
            // Excede las 3 horas de la papeleta (ver la ocurrencia de porteria "exceso de papeleta").
            ['VL-0011', 'COMISION', 'COM_TRAMITE', null, 'PS-0124', '2026-09-29', '12:50:00', '15:50:00', 0, 220, 'Trámite documentario en la Gerencia Regional', 'Retornó 40 minutos después del límite de 3 horas', 'APROBADO', 'pgutierrez'],
            ['VL-0006', 'PERM_SALUD', 'SAL_CONSULTA', null, null, '2026-09-27', '14:20:00', '16:00:00', 0, null, 'Cita médica programada', null, 'PENDIENTE', null],
            ['VL-0009', 'PERM_PARTIC', 'PAR_PERSONAL', null, 'PS-0125', '2026-09-25', '09:00:00', '11:00:00', 0, null, 'Trámite personal', 'Rechazo: no se pidió con 24 horas de anticipación', 'RECHAZADO', 'pgutierrez'],
            ['VL-0012', 'LACTANCIA', 'LAC_HORA', null, 'PS-0126', '2026-09-28', '08:00:00', '09:00:00', 0, 60, 'Hora diaria de lactancia (RIT, Art. 60)', null, 'APROBADO', 'pgutierrez'],
            ['VL-0005', 'ONOMASTICO', null, null, 'PS-0127', '2026-09-14', null, null, 1, null, 'Día de onomástico', null, 'APROBADO', 'pgutierrez'],
            ['VL-0004', 'CAPACITACION', 'CAP_CURSO', 'constancia-capacitación-0004.pdf', null, '2026-10-05', null, null, 1, null, 'Curso de actualización en salud bucal', null, 'PENDIENTE', null],
            ['VL-0008', 'COMISION', 'COM_TRAMITE', null, 'PS-0128', '2026-09-30', '08:30:00', '10:00:00', 0, null, 'Trámite en la SUNAT', 'Registrada dos veces', 'ANULADO', null],
        ];
        $this->sembrarSiVacia('Solicitudes.Papeleta', array_map(fn (array $p) => [
            'VinculoLaboralId' => $vinculos[$p[0]],
            'TipoPapeletaId' => $tipos[$p[1]],
            'MotivoPapeletaId' => $p[2] === null ? null : $motivos[$p[2]],
            'DocumentoSustentoId' => $p[3] === null ? null : $documentos[$p[3]],
            'UsuarioRegistroId' => $usuarios['rvargas'],
            'UsuarioAutorizacionId' => $p[13] === null ? null : $usuarios[$p[13]],
            'PapeletaNumero' => $p[4],
            'PapeletaFecha' => $p[5],
            'PapeletaHoraSalida' => $p[6],
            'PapeletaHoraRetorno' => $p[7],
            'PapeletaEsDiaCompleto' => $p[8],
            'PapeletaMinutosUtilizados' => $p[9],
            'PapeletaMotivo' => $p[10],
            'PapeletaObservacion' => $p[11],
            'PapeletaFechaRegistro' => date('Y-m-d H:i:s', strtotime($p[5].' 07:00:00 -1 day')),
            'PapeletaFechaResolucion' => $p[13] === null ? null : date('Y-m-d H:i:s', strtotime($p[5].' 07:30:00')),
            'PapeletaEstado' => $p[12],
        ], $papeletas));
    }

    private function licencias(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $tipos = $this->ids('Solicitudes.TipoLicencia', 'TipoLicenciaCodigo', 'TipoLicenciaId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [vinculo, tipo, documento|null, resolucion|null, inicio, fin, motivo|null, estado]
        $licencias = [
            ['VL-0001', 'ENFERMEDAD', 'certificado-medico-0001.pdf', 'RD-0451-2026', '2026-09-14', '2026-09-18', 'Cirugía programada', 'APROBADO'],
            ['VL-0006', 'FALLECIMIENTO', null, 'RD-0398-2026', '2026-08-20', '2026-08-24', 'Fallecimiento de familiar directo (5 días)', 'APROBADO'],
            ['VL-0009', 'PATERNIDAD', null, null, '2026-10-05', '2026-10-14', 'Nacimiento de su hijo (10 días corridos)', 'PENDIENTE'],
            ['VL-0005', 'SIN_GOCE', null, null, '2026-10-12', '2026-10-16', 'Motivos particulares', 'RECHAZADO'],
            ['VL-0011', 'CAPACITACION', 'constancia-capacitación-0004.pdf', null, '2026-11-02', '2026-11-06', 'Diplomado en gestión pública', 'PENDIENTE'],
            ['VL-0004', 'ESTUDIOS', null, 'RD-0310-2026', '2026-07-01', '2026-07-31', 'Rotación académica de maestría', 'APROBADO'],
        ];
        $this->sembrarSiVacia('Solicitudes.Licencia', array_map(fn (array $l) => [
            'VinculoLaboralId' => $vinculos[$l[0]],
            'TipoLicenciaId' => $tipos[$l[1]],
            'DocumentoSustentoId' => $l[2] === null ? null : $documentos[$l[2]],
            'UsuarioRegistroId' => $usuarios['rvargas'],
            'LicenciaNumeroResolucion' => $l[3],
            'LicenciaFechaInicio' => $l[4],
            'LicenciaFechaFin' => $l[5],
            'LicenciaMotivo' => $l[6],
            'LicenciaFechaRegistro' => date('Y-m-d H:i:s', strtotime($l[4].' 09:00:00 -10 days')),
            'LicenciaEstado' => $l[7],
        ], $licencias));
    }

    private function descansosMedicosYConstataciones(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [vinculo, documento|null, citt|null, diagnostico|null, inicio, fin, observacion|null, estado]
        $descansos = [
            ['VL-0009', 'certificado-medico-0001.pdf', 'CITT-4471', 'Lumbalgia aguda', '2026-09-29', '2026-10-03', null, 'APROBADO'],
            ['VL-0012', null, 'CITT-4390', 'Infección respiratoria aguda', '2026-09-10', '2026-09-12', 'Certificado entregado en físico', 'APROBADO'],
            ['VL-0003', 'citt-0002.pdf', 'CITT-4502', 'Gastroenteritis', '2026-10-01', '2026-10-07', null, 'PENDIENTE'],
            ['VL-0006', null, null, 'Malestar general', '2026-09-03', '2026-09-04', 'Sin CITT ni certificado: no se pudo sustentar', 'RECHAZADO'],
            ['VL-0001', null, 'CITT-4001', null, '2026-08-03', '2026-08-05', 'Registrado por error', 'ANULADO'],
        ];
        $this->sembrarSiVacia('Solicitudes.DescansoMedico', array_map(fn (array $d) => [
            'VinculoLaboralId' => $vinculos[$d[0]],
            'DocumentoSustentoId' => $d[1] === null ? null : $documentos[$d[1]],
            'DescansoMedicoNumeroCitt' => $d[2],
            'DescansoMedicoDiagnostico' => $d[3],
            'DescansoMedicoFechaInicio' => $d[4],
            'DescansoMedicoFechaFin' => $d[5],
            'DescansoMedicoObservacion' => $d[6],
            'DescansoMedicoFechaRegistro' => date('Y-m-d H:i:s', strtotime($d[4].' 08:00:00')),
            'DescansoMedicoEstado' => $d[7],
        ], $descansos));

        $porCitt = DB::table('Solicitudes.DescansoMedico')->whereNotNull('DescansoMedicoNumeroCitt')->pluck('DescansoMedicoId', 'DescansoMedicoNumeroCitt')->map(fn ($i) => (int) $i)->all();

        // [vinculo, descanso(citt)|null, fecha, direccion|null, resultado|null, estado, registra]
        $constataciones = [
            ['VL-0009', 'CITT-4471', '2026-09-30', 'Jr. Los Pinos 123, Trujillo', 'Se encontró al servidor en su domicilio, en reposo', 'CONFORME', 'gdangelo'],
            ['VL-0012', 'CITT-4390', '2026-09-11', 'Mz. B Lt. 7, Urb. Santa María, Moche', 'No se encontró al servidor; los vecinos indican que viajó', 'NO_CONFORME', 'gdangelo'],
            ['VL-0003', 'CITT-4502', '2026-10-02', 'Av. Perú 456, Florencia de Mora', null, 'PENDIENTE', 'gdangelo'],
            ['VL-0006', null, '2026-09-20', 'Calle Bolognesi 88, La Esperanza', null, 'ANULADO', 'gdangelo'],
        ];
        $this->sembrarSiVacia('Solicitudes.ConstatacionDomiciliaria', array_map(fn (array $c) => [
            'VinculoLaboralId' => $vinculos[$c[0]],
            'DescansoMedicoId' => $c[1] === null ? null : $porCitt[$c[1]],
            'UsuarioRegistroId' => $usuarios[$c[6]],
            'ConstatacionDomiciliariaFecha' => $c[2],
            'ConstatacionDomiciliariaDireccion' => $c[3],
            'ConstatacionDomiciliariaResultado' => $c[4],
            'ConstatacionDomiciliariaEstado' => $c[5],
        ], $constataciones));
    }

    private function programaciones(): void
    {
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $tipos = $this->ids('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionCodigo', 'TipoPeriodoProgramacionId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [eess, tipo, codigo|null, anio, mes|null, numero|null, inicio, fin, observacion|null, publicacion|null, estado]
        $periodos = [
            ['EESS-LE-01', 'MENSUAL', 'PGM-LE-2026-09', 2026, 9, null, '2026-09-01', '2026-09-30', null, '2026-08-28 10:00:00', 'CERRADA'],
            ['EESS-LE-01', 'MENSUAL', 'PGM-LE-2026-10', 2026, 10, null, '2026-10-01', '2026-10-31', 'Incluye guardias hospitalarias de 12 horas', '2026-09-28 09:30:00', 'PUBLICADA'],
            ['EESS-EP-01', 'QUINCENAL', null, 2026, 10, 1, '2026-10-01', '2026-10-15', null, null, 'BORRADOR'],
            ['EESS-EP-01', 'QUINCENAL', null, 2026, 10, 2, '2026-10-16', '2026-10-31', 'Pendiente de revisión del jefe del establecimiento', null, 'BORRADOR'],
            ['EESS-FM-01', 'SEMANAL', null, 2026, 10, 1, '2026-10-01', '2026-10-07', null, null, 'BORRADOR'],
            ['SEDE-RRHH', 'MENSUAL', null, 2026, 10, null, '2026-10-01', '2026-10-31', 'Se reemplazó por la programación de la jefatura', null, 'ANULADA'],
            ['EESS-LA-01', 'EXTRAORD', null, 2026, 10, null, '2026-10-10', '2026-10-12', 'Campaña extramural de vacunación', '2026-10-01 11:00:00', 'PUBLICADA'],
        ];
        $this->sembrarSiVacia('Programacion.ProgramacionPeriodo', array_map(fn (array $p) => [
            'EessId' => $eess[$p[0]],
            'TipoPeriodoProgramacionId' => $tipos[$p[1]],
            'UsuarioRegistroId' => $usuarios['rvargas'],
            'ProgramacionPeriodoCodigo' => $p[2],
            'ProgramacionPeriodoAnio' => $p[3],
            'ProgramacionPeriodoMes' => $p[4],
            'ProgramacionPeriodoNumero' => $p[5],
            'ProgramacionPeriodoFechaInicio' => $p[6],
            'ProgramacionPeriodoFechaFin' => $p[7],
            'ProgramacionPeriodoObservacion' => $p[8],
            'ProgramacionPeriodoFechaRegistro' => date('Y-m-d H:i:s', strtotime($p[6].' 08:00:00 -10 days')),
            'ProgramacionPeriodoFechaPublicacion' => $p[9],
            'ProgramacionPeriodoEstado' => $p[10],
        ], $periodos));

        $periodoDe = fn (string $codigo) => (int) DB::table('Programacion.ProgramacionPeriodo')->where('ProgramacionPeriodoCodigo', $codigo)->value('ProgramacionPeriodoId');

        // [eess, documento, tipo|null, programacion(codigo)|null, codigo, anio, mes, numero|null, fecha documento, n.o documento|null, motivo|null, observacion|null, estado]
        $cargas = [
            ['EESS-LE-01', 'resolucion-comision-0003.pdf', 'MENSUAL', 'PGM-LE-2026-10', 'PROG-2026-10-001', 2026, 10, null, '2026-09-27', 'OF-0345-2026-EESS-LE', 'Programación de octubre', null, 'CONFORME'],
            ['EESS-EP-01', 'citt-0002.pdf', 'QUINCENAL', null, 'PROG-2026-10-002', 2026, 10, 1, '2026-09-30', 'OF-0102-2026-EESS-EP', 'Primera quincena de octubre', null, 'REGISTRADO'],
            ['EESS-FM-01', 'acta-duelo-0006.pdf', 'SEMANAL', null, 'PROG-2026-10-003', 2026, 10, null, '2026-09-30', null, null, 'Falta la firma del jefe del establecimiento', 'OBSERVADO'],
            ['EESS-LE-01', 'certificado-medico-0001.pdf', 'MENSUAL', 'PGM-LE-2026-09', 'PROG-2026-09-001', 2026, 9, null, '2026-08-27', 'OF-0301-2026-EESS-LE', 'Programación de setiembre', null, 'CONFORME'],
            ['EESS-SA-01', 'foto-constatación-0005.jpg', 'MENSUAL', null, 'PROG-2026-09-002', 2026, 9, null, '2026-08-29', null, null, 'Se cargó el documento equivocado', 'ANULADO'],
        ];
        $this->sembrarSiVacia('Programacion.CargaProgramacion', array_map(fn (array $c) => [
            'EessId' => $eess[$c[0]],
            'DocumentoSustentoId' => $documentos[$c[1]],
            'UsuarioRegistroId' => $usuarios['rvargas'],
            'TipoPeriodoProgramacionId' => $c[2] === null ? null : $tipos[$c[2]],
            'ProgramacionPeriodoId' => $c[3] === null ? null : $periodoDe($c[3]),
            'CargaProgramacionCodigo' => $c[4],
            'CargaProgramacionAnio' => $c[5],
            'CargaProgramacionMes' => $c[6],
            'CargaProgramacionNumero' => $c[7],
            'CargaProgramacionFechaDocumento' => $c[8],
            'CargaProgramacionDocumentoNumero' => $c[9],
            'CargaProgramacionMotivo' => $c[10],
            'CargaProgramacionObservacion' => $c[11],
            'CargaProgramacionFechaRegistro' => $c[8].' 15:00:00',
            'CargaProgramacionEstado' => $c[12],
        ], $cargas));
    }

    private function informesDeGuardiaComunitaria(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // Solo personal del D.L. 276 y SERUMS hace guardia comunitaria (RIT, Art. 20); dura 12 horas.
        // [vinculo, documento|null, fecha, inicio, fin, descripcion, estado]
        $informes = [
            ['VL-0001', null, '2026-09-26', '08:00:00', '20:00:00', 'Visitas domiciliarias a gestantes del sector 3 y educación sanitaria en el colegio N.º 80041', 'PENDIENTE'],
            ['VL-0006', 'acta-duelo-0006.pdf', '2026-09-19', '07:30:00', '19:30:00', 'Campaña de vacunación en el AA.HH. Santa Rosa', 'APROBADO'],
            ['VL-0004', 'foto-constatación-0005.jpg', '2026-09-12', '08:00:00', '20:00:00', 'Atención odontológica preventiva en la comunidad', 'APROBADO'],
            ['VL-0007', null, '2026-09-05', '08:00:00', '20:00:00', 'Actividad extramural del SERUMS: charlas de nutrición', 'PENDIENTE'],
            ['VL-0012', null, '2026-09-12', null, null, 'Tamizaje de anemia', 'RECHAZADO'],
            ['VL-0001', null, '2026-08-29', '08:00:00', '20:00:00', 'Registrado por error', 'ANULADO'],
        ];
        $this->sembrarSiVacia('Programacion.InformeGuardiaComunitaria', array_map(fn (array $i) => [
            'VinculoLaboralId' => $vinculos[$i[0]],
            'DocumentoSustentoId' => $i[1] === null ? null : $documentos[$i[1]],
            'InformeGuardiaComunitariaFecha' => $i[2],
            'InformeGuardiaComunitariaHoraInicio' => $i[3],
            'InformeGuardiaComunitariaHoraFin' => $i[4],
            'InformeGuardiaComunitariaDescripcion' => $i[5],
            'InformeGuardiaComunitariaEstado' => $i[6],
        ], $informes));
    }

    private function asignacionesDeHorario(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $horarios = $this->ids('Configuracion.Horario', 'HorarioCodigo', 'HorarioId');

        // Un solo horario vigente por vinculo; los cambios dejan historico. [vinculo, horario, inicio, fin|null, observacion|null, estado]
        $asignaciones = [
            ['VL-0001', 'HOR-LE-ROT', '2026-01-01', null, 'Rotativo del C.S. La Esperanza', 1],
            ['VL-0008', 'HOR-ADM-LV', '2026-01-01', null, null, 1],
            ['VL-0011', 'HOR-ADM-LV', '2026-01-01', null, null, 1],
            ['VL-0005', 'HOR-CSMC-ADM', '2026-02-01', null, null, 1],
            ['VL-0002', 'HOR-ESS-M', '2026-01-01', '2026-06-30', 'Turno mañana hasta junio', 1],
            ['VL-0002', 'HOR-ESS-T', '2026-07-01', null, 'Pasó al turno tarde', 1],
            ['VL-0003', 'HOR-FM-OLD', '2020-08-01', '2025-12-31', 'Horario antiguo (ya no se usa)', 1],
            ['VL-0003', 'HOR-ESS-M', '2026-01-01', null, null, 1],
            ['VL-0006', 'HOR-ESS-T', '2026-01-01', null, null, 1],
            ['VL-0004', 'HOR-ESS-M', '2026-03-01', null, 'Asignación desactivada', 0],
        ];
        $this->sembrarSiVacia('Personal.AsignacionHorario', array_map(fn (array $a) => [
            'VinculoLaboralId' => $vinculos[$a[0]],
            'HorarioId' => $horarios[$a[1]],
            'AsignacionHorarioFechaInicio' => $a[2],
            'AsignacionHorarioFechaFin' => $a[3],
            'AsignacionHorarioObservacion' => $a[4],
            'AsignacionHorarioFechaRegistro' => $a[2].' 08:00:00',
            'AsignacionHorarioEstado' => $a[5],
        ], $asignaciones));
    }

    private function responsablesDeEess(): void
    {
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $tipos = $this->ids('Organizacion.TipoResponsabilidad', 'TipoResponsabilidadCodigo', 'TipoResponsabilidadId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // [eess, vinculo, tipo, documento|null, inicio, fin|null, n.o documento|null, observacion|null, estado]
        $responsables = [
            ['EESS-LE-01', 'VL-0001', 'JEFE_EESS', 'resolucion-comision-0003.pdf', '2026-01-01', null, 'RD-0012-2026', null, 1],
            ['EESS-LE-01', 'VL-0001', 'RESP_ASISTENCIA', null, '2026-03-01', null, 'MEMO-0045-2026', 'Encargado del control de asistencia y permanencia', 1],
            ['EESS-EP-01', 'VL-0002', 'JEFE_EESS', null, '2020-01-01', null, 'RD-0088-2020', null, 1],
            ['EESS-EP-01', 'VL-0004', 'JEFE_EESS', null, '2019-04-01', '2019-12-31', 'RD-0101-2019', 'Primer jefe del establecimiento', 1],
            ['SEDE-RRHH', 'VL-0011', 'RESP_PERSONAL', null, '2026-01-01', null, 'MEMO-0003-2026', null, 1],
            ['EESS-FM-01', 'VL-0003', 'RESP_PROGRAMACION', null, '2026-02-01', null, null, 'Designación dejada sin efecto', 0],
            ['SEDE-CSMC', 'VL-0005', 'JEFE_EESS', null, '2026-02-01', null, 'RD-0033-2026', null, 1],
        ];
        $this->sembrarSiVacia('Organizacion.ResponsableEess', array_map(fn (array $r) => [
            'EessId' => $eess[$r[0]],
            'VinculoLaboralId' => $vinculos[$r[1]],
            'TipoResponsabilidadId' => $tipos[$r[2]],
            'DocumentoSustentoId' => $r[3] === null ? null : $documentos[$r[3]],
            'ResponsableEessFechaInicio' => $r[4],
            'ResponsableEessFechaFin' => $r[5],
            'ResponsableEessDocumentoNumero' => $r[6],
            'ResponsableEessObservacion' => $r[7],
            'ResponsableEessFechaRegistro' => $r[4].' 08:00:00',
            'ResponsableEessEstado' => $r[8],
        ], $responsables));
    }

    private function rolesYAmbitosDeUsuario(): void
    {
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $roles = $this->ids('Seguridad.Rol', 'RolCodigo', 'RolId');
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');
        $microredes = $this->ids('Organizacion.Microred', 'MicroredCodigo', 'MicroredId');

        // [usuario, rol, inicio, fin|null, estado]
        $asignaciones = [
            ['pgutierrez', 'ADMIN', '2026-09-02', null, 1], ['pgutierrez', 'RRHH_RED', '2026-09-02', null, 1],
            ['gdangelo', 'RRHH_RED', '2026-09-02', null, 1], ['mquispe', 'TRABAJADOR', '2026-09-02', null, 1],
            ['crojas', 'TRABAJADOR', '2026-09-02', null, 1], ['crojas', 'RESP_EESS', '2026-09-02', null, 1],
            ['lcastillo', 'TRABAJADOR', '2026-09-02', null, 1], ['lcastillo', 'PROGRAMADOR', '2026-09-02', null, 1],
            ['rvargas', 'RESP_EESS', '2026-09-02', null, 1], ['vsanchez', 'PORTERIA', '2026-09-02', null, 1],
            ['jretirado', 'TRABAJADOR', '2022-01-03', '2024-06-30', 0],
        ];
        $this->sembrarSiVacia('Seguridad.UsuarioRol', array_map(fn (array $a) => [
            'UsuarioId' => $usuarios[$a[0]], 'RolId' => $roles[$a[1]], 'UsuarioRolFechaInicio' => $a[2], 'UsuarioRolFechaFin' => $a[3], 'UsuarioRolEstado' => $a[4],
        ], $asignaciones));

        // Ambito: Red (sin microred ni EESS), una Microred o un EESS. [usuario, microred|null, eess|null, estado]
        $ambitos = [
            ['pgutierrez', null, null, 1], ['gdangelo', null, null, 1], ['mquispe', null, 'EESS-LE-01', 1], ['crojas', null, 'EESS-EP-01', 1],
            ['lcastillo', 'MR-FM', null, 1], ['rvargas', 'MR-SEDE', null, 1], ['vsanchez', null, 'SEDE-RRHH', 1], ['jretirado', null, 'EESS-LE-01', 0],
        ];
        $this->sembrarSiVacia('Seguridad.UsuarioAmbito', array_map(fn (array $a) => [
            'UsuarioId' => $usuarios[$a[0]],
            'MicroredId' => $a[1] === null ? null : $microredes[$a[1]],
            'EessId' => $a[2] === null ? null : $eess[$a[2]],
            'UsuarioAmbitoEstado' => $a[3],
        ], $ambitos));

        // Sesiones de acceso (las escribira el login; hoy son solo de lectura). [usuario, inicio, fin|null, ip, resultado]
        $sesiones = [
            ['pgutierrez', '2026-09-30 07:45:00', '2026-09-30 16:20:00', '192.168.10.21', 'EXITOSO'],
            ['pgutierrez', '2026-10-01 07:50:00', '2026-10-01 17:05:00', '192.168.10.21', 'EXITOSO'],
            ['gdangelo', '2026-10-01 08:10:00', '2026-10-01 12:40:00', '192.168.10.25', 'EXITOSO'],
            ['rvargas', '2026-10-01 08:30:00', null, '192.168.10.30', 'EXITOSO'],
            ['rvargas', '2026-09-30 08:28:00', null, '192.168.10.30', 'CLAVE_INCORRECTA'],
            ['mquispe', '2026-09-29 09:00:00', '2026-09-29 09:25:00', '192.168.20.14', 'EXITOSO'],
            ['crojas', '2026-09-28 07:20:00', '2026-09-28 13:45:00', '192.168.30.12', 'EXITOSO'],
            ['jretirado', '2026-09-27 21:15:00', null, '201.240.55.18', 'USUARIO_INACTIVO'],
            ['vsanchez', '2026-10-02 06:55:00', null, '192.168.10.40', 'EXITOSO'],
            ['lcastillo', '2026-09-26 10:05:00', null, '192.168.40.11', 'CLAVE_INCORRECTA'],
        ];
        $this->sembrarSiVacia('Seguridad.SesionAcceso', array_map(fn (array $s) => [
            'UsuarioId' => $usuarios[$s[0]], 'SesionAccesoFechaInicio' => $s[1], 'SesionAccesoFechaFin' => $s[2],
            'SesionAccesoDireccionIp' => $s[3], 'SesionAccesoResultado' => $s[4],
        ], $sesiones));
    }

    private function consolidados(): void
    {
        if (DB::table('Consolidacion.ConsolidadoAsistencia')->exists()) {
            return;
        }
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $periodos = DB::table('Consolidacion.PeriodoAsistencia')->where('PeriodoAsistenciaAnio', 2026)->pluck('PeriodoAsistenciaId', 'PeriodoAsistenciaMes');

        // Agosto ya esta cerrado: sus consolidados son definitivos. [vinculo, trabajados, faltas, justificadas, tardanza, extra]
        $agosto = [
            ['VL-0001', 21, 0, 0, 0, 120], ['VL-0002', 22, 0, 0, 5, 60], ['VL-0003', 19, 1, 2, 22, 0], ['VL-0006', 20, 0, 1, 11, 0],
        ];
        foreach ($agosto as [$v, $trabajados, $faltas, $justificadas, $tardanza, $extra]) {
            DB::table('Consolidacion.ConsolidadoAsistencia')->insert([
                'PeriodoAsistenciaId' => $periodos[8], 'VinculoLaboralId' => $vinculos[$v],
                'ConsolidadoAsistenciaDiasTrabajados' => $trabajados, 'ConsolidadoAsistenciaDiasFalta' => $faltas,
                'ConsolidadoAsistenciaDiasFaltaJustificada' => $justificadas, 'ConsolidadoAsistenciaMinutosTardanza' => $tardanza,
                'ConsolidadoAsistenciaMinutosExtra' => $extra, 'ConsolidadoAsistenciaFechaGeneracion' => '2026-09-05 10:00:00',
                'ConsolidadoAsistenciaEstado' => 'CERRADO',
            ]);
        }

        // Setiembre (en proceso): se genera con el mismo servicio que usa la API, a partir de la asistencia diaria sembrada.
        $setiembre = PeriodoAsistencia::query()->findOrFail($periodos[9]);
        app(ConsolidadoAsistenciaService::class)->generar($setiembre);
        DB::table('Consolidacion.ConsolidadoAsistencia')->where(['PeriodoAsistenciaId' => $periodos[9], 'VinculoLaboralId' => $vinculos['VL-0001']])->update(['ConsolidadoAsistenciaEstado' => 'CONFORME']);
        DB::table('Consolidacion.ConsolidadoAsistencia')->where(['PeriodoAsistenciaId' => $periodos[9], 'VinculoLaboralId' => $vinculos['VL-0004']])->update(['ConsolidadoAsistenciaEstado' => 'OBSERVADO']);
    }

    private function compensacionesHorarias(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $tipos = $this->ids('Compensaciones.TipoCompensacion', 'TipoCompensacionCodigo', 'TipoCompensacionId');
        $asistenciaDe = fn (string $codigo, string $fecha) => DB::table('Asistencia.AsistenciaDiaria')
            ->where(['VinculoLaboralId' => $vinculos[$codigo], 'AsistenciaDiariaFecha' => $fecha])->value('AsistenciaDiariaId');

        // RIT Art. 17: se compensa con autorizacion previa, de una hora como minimo y hasta el mes siguiente.
        // [vinculo, tipo, asistencia|null, autoriza|null, generacion, generadas, devueltas, limite|null, previa, observacion|null, estado]
        $compensaciones = [
            ['VL-0011', 'HORA_EXTRA', ['VL-0011', '2026-09-30'], 'gdangelo', '2026-09-24 18:00:00', 2.00, 1.00, '2026-10-30', 1, 'Cierre de planillas de setiembre', 'APROBADO'],
            ['VL-0008', 'GUARDIA', null, 'pgutierrez', '2026-09-20 08:00:00', 12.00, 0.00, '2026-10-31', 1, 'Guardia comunitaria del sábado', 'PENDIENTE'],
            ['VL-0001', 'FERIADO', null, 'pgutierrez', '2026-09-08 08:00:00', 6.00, 6.00, '2026-10-30', 1, 'Trabajo en día no laborable', 'CONSUMIDO'],
            ['VL-0006', 'HORA_EXTRA', null, null, '2026-09-26 19:00:00', 1.50, 0.00, null, 0, 'Sin autorización previa del jefe: no procede', 'PENDIENTE'],
            ['VL-0009', 'PERMISO_COMP', null, 'pgutierrez', '2026-07-28 08:00:00', 2.00, 0.00, '2026-08-31', 1, 'No devolvió las horas a tiempo', 'VENCIDO'],
            ['VL-0004', 'HORA_EXTRA', null, null, '2026-09-15 18:30:00', 3.00, 0.00, null, 0, 'Registrada por error', 'ANULADO'],
        ];
        $this->sembrarSiVacia('Compensaciones.CompensacionHoraria', array_map(fn (array $c) => [
            'VinculoLaboralId' => $vinculos[$c[0]],
            'TipoCompensacionId' => $tipos[$c[1]],
            'AsistenciaDiariaId' => $c[2] === null ? null : $asistenciaDe(...$c[2]),
            'CompensacionHorariaAutorizadoPor' => $c[3] === null ? null : $usuarios[$c[3]],
            'CompensacionHorariaFechaGeneracion' => $c[4],
            'CompensacionHorariaHorasGeneradas' => $c[5],
            'CompensacionHorariaHorasDevueltas' => $c[6],
            'CompensacionHorariaFechaLimite' => $c[7],
            'CompensacionHorariaAutorizadoPreviamente' => $c[8],
            'CompensacionHorariaObservacion' => $c[9],
            'CompensacionHorariaEstado' => $c[10],
        ], $compensaciones));
    }

    private function periodosVacacionales(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');

        // RIT Art. 68: 30 dias calendario por anio completo de servicios. [vinculo, anio, inicio, fin, ganados, disponibles, estado]
        $periodos = [
            ['VL-0001', 2025, '2025-03-01', '2026-02-28', 30, 10, 'CERRADO'],
            ['VL-0001', 2026, '2026-03-01', '2027-02-28', 30, 30, 'ABIERTO'],
            ['VL-0002', 2026, '2026-06-15', '2027-06-14', 30, 15, 'ABIERTO'],
            ['VL-0008', 2026, '2026-01-10', '2027-01-09', 30, 30, 'ABIERTO'],
            ['VL-0005', 2026, '2026-02-01', '2027-01-31', 30, 20, 'ABIERTO'],
            ['VL-0009', 2024, '2024-05-02', '2025-05-01', 30, 30, 'ANULADO'],
        ];
        $this->sembrarSiVacia('Vacaciones.PeriodoVacacional', array_map(fn (array $p) => [
            'VinculoLaboralId' => $vinculos[$p[0]],
            'PeriodoVacacionalAnio' => $p[1],
            'PeriodoVacacionalFechaInicio' => $p[2],
            'PeriodoVacacionalFechaFin' => $p[3],
            'PeriodoVacacionalDiasGanados' => $p[4],
            'PeriodoVacacionalDiasDisponibles' => $p[5],
            'PeriodoVacacionalEstado' => $p[6],
        ], $periodos));
    }

    private function expedientesPad(): void
    {
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $tipos = $this->ids('Disciplina.TipoFaltaDisciplinaria', 'TipoFaltaDisciplinariaCodigo', 'TipoFaltaDisciplinariaId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // Ley 30057 y RIT Art. 100 a 107. [vinculo, tipo, documento|null, numero|null, inicio, fin|null, descripcion, sancion|null, estado]
        $expedientes = [
            ['VL-0006', 'INASIST_INJUST', 'resolucion-comision-0003.pdf', 'PAD-2026-001', '2026-09-10', null, 'Más de 3 días consecutivos de inasistencia injustificada (RIT, Art. 23)', null, 'EN_PROCESO'],
            ['VL-0008', 'ABANDONO', null, 'PAD-2026-002', '2026-09-30', null, 'Abandono del puesto de trabajo sin papeleta de salida', null, 'INICIADO'],
            ['VL-0004', 'TARDANZA_REIT', null, 'PAD-2026-003', '2026-06-01', '2026-07-15', 'Tardanzas reiteradas durante tres meses', 'Amonestación escrita', 'RESUELTO'],
            ['VL-0012', 'NEGLIGENCIA', null, 'PAD-2026-004', '2026-05-02', '2026-06-20', 'Negligencia en el registro de muestras de laboratorio', 'Suspensión sin goce de remuneraciones por 15 días', 'RESUELTO'],
            ['VL-0009', 'MARCA_TERCERO', null, 'PAD-2025-017', '2025-11-03', '2026-01-20', 'Presunta marcación por tercero', null, 'ARCHIVADO'],
            ['VL-0005', 'INCUMPL_HORARIO', null, null, '2026-08-01', null, 'Abierto por error', null, 'ANULADO'],
        ];
        $this->sembrarSiVacia('Disciplina.ExpedientePad', array_map(fn (array $e) => [
            'VinculoLaboralId' => $vinculos[$e[0]],
            'TipoFaltaDisciplinariaId' => $tipos[$e[1]],
            'DocumentoSustentoId' => $e[2] === null ? null : $documentos[$e[2]],
            'ExpedientePadNumero' => $e[3],
            'ExpedientePadFechaInicio' => $e[4],
            'ExpedientePadFechaFin' => $e[5],
            'ExpedientePadDescripcion' => $e[6],
            'ExpedientePadSancion' => $e[7],
            'ExpedientePadEstado' => $e[8],
        ], $expedientes));
    }

    private function supervisionesInopinadas(): void
    {
        $eess = $this->ids('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId');
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');

        // RIT Art. 24 y 29. [eess, vinculo|null, supervisor, acta|null, fecha y hora, resultado|null, observacion|null, estado]
        $supervisiones = [
            ['SEDE-RRHH', 'VL-0008', 'pgutierrez', null, '2026-09-29 11:30:00', 'El servidor no se encontraba en su puesto', 'Se retiró sin papeleta de salida; el jefe responde en 3 días', 'OBSERVADO'],
            ['EESS-LE-01', null, 'pgutierrez', 'foto-constatación-0005.jpg', '2026-09-22 08:15:00', 'Todo el personal programado estaba en su puesto', null, 'CONFORME'],
            ['EESS-EP-01', 'VL-0002', 'gdangelo', null, '2026-09-24 14:00:00', 'Personal presente y puntual', null, 'CONFORME'],
            ['EESS-LE-02', 'VL-0006', 'pgutierrez', null, '2026-09-28 09:40:00', 'Servidor ausente sin justificación', 'Se solicita el informe del jefe del establecimiento', 'OBSERVADO'],
            ['EESS-FM-01', null, 'gdangelo', null, '2026-10-01 07:50:00', null, null, 'REGISTRADO'],
            ['SEDE-CSMC', null, 'gdangelo', null, '2026-09-15 10:00:00', null, 'Supervisión programada y no realizada', 'ANULADO'],
        ];
        $this->sembrarSiVacia('Disciplina.SupervisionInopinada', array_map(fn (array $s) => [
            'EessId' => $eess[$s[0]],
            'VinculoLaboralId' => $s[1] === null ? null : $vinculos[$s[1]],
            'UsuarioId' => $usuarios[$s[2]],
            'DocumentoSustentoId' => $s[3] === null ? null : $documentos[$s[3]],
            'SupervisionInopinadaFechaHora' => $s[4],
            'SupervisionInopinadaResultado' => $s[5],
            'SupervisionInopinadaObservacion' => $s[6],
            'SupervisionInopinadaEstado' => $s[7],
        ], $supervisiones));
    }

    /**
     * @param  list<array<string,mixed>>  $filas
     */
    private function sembrarSiVacia(string $tabla, array $filas): void
    {
        if (DB::table($tabla)->exists()) {
            return;
        }
        foreach ($filas as $fila) {
            DB::table($tabla)->insert($fila);
        }
    }
}
