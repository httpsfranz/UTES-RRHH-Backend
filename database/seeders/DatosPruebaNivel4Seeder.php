<?php

namespace Database\Seeders;

use App\Models\Asistencia\AjusteMarcacion;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Programacion\CambioTurno;
use App\Models\Programacion\ProgramacionTrabajador;
use App\Services\AjusteMarcacionService;
use App\Services\CambioTurnoService;
use App\Services\LiquidacionDescuentoService;
use Database\Seeders\Concerns\SiembraCatalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba de los niveles 4, 5 y 6 (programacion de trabajadores y turnos, cambios de turno, ajustes de
 * marcacion, detalle del consolidado, liquidacion de descuentos y rol vacacional con sus goces). Corre al final de
 * DatosPruebaSeeder, despues de Nivel 3. Es idempotente (cada tabla se siembra solo si esta vacia) y deterministico.
 *
 * Lo que tiene un efecto sobre otras tablas (aprobar un cambio de turno o un ajuste, generar y remitir una liquidacion)
 * se hace con los mismos servicios que usa la API, para que los datos queden coherentes. "Hoy" es 2026-10-02.
 */
class DatosPruebaNivel4Seeder extends Seeder
{
    use SiembraCatalogos;

    public function run(): void
    {
        $this->personalDeLaEsperanza();

        // Nivel 4
        $this->programacionDeTrabajadoresYTurnos();
        $this->ajustesDeMarcacion();
        $this->detalleDeConsolidadosCerrados();
        $this->liquidaciones();
        $this->rolVacacional();

        // Niveles 5 y 6
        $this->gocesVacacionales();
        $this->cambiosDeTurno();
    }

    /** Dos enfermeros mas en el C.S. La Esperanza: sin ellos no hay con quien permutar ni quien reemplace. */
    private function personalDeLaEsperanza(): void
    {
        if (DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', 'VL-0015')->exists()) {
            return;
        }
        $tipo = (int) DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadCodigo', 'DNI')->value('TipoDocumentoIdentidadId');
        $profesion = (int) DB::table('Personal.Profesion')->where('ProfesionCodigo', 'ENFERMERIA')->value('ProfesionId');
        $eess = (int) DB::table('Organizacion.EstablecimientoSalud')->where('EessCodigo', 'EESS-LE-01')->value('EessId');
        $cargo = (int) DB::table('Personal.Cargo')->where('CargoNombre', 'Enfermero(a)')->value('CargoId');
        $condicion = (int) DB::table('Personal.CondicionLaboral')->where('CondicionLaboralCodigo', 'NOMBRADO')->value('CondicionLaboralId');
        $regimen = (int) DB::table('Personal.RegimenLaboral')->where('RegimenLaboralCodigo', 'DL276')->value('RegimenLaboralId');

        // Personas FICTICIAS (documentos 700000NN que no corresponden a nadie). [documento, nombres, paterno, materno, sexo, nacimiento, vinculo, airhsp, plaza, inicio]
        $personal = [
            ['70000013', 'Marisol Ángela', 'Cabrera', 'Rímac', 'F', '1986-06-11', 'VL-0015', '100015', 'P-0115', '2014-05-01'],
            ['70000014', 'Renzo Alexander', 'Zavaleta', 'Bobadilla', 'M', '1989-02-23', 'VL-0016', '100016', 'P-0116', '2016-08-01'],
        ];
        foreach ($personal as [$documento, $nombres, $paterno, $materno, $sexo, $nacimiento, $codigo, $airhsp, $plaza, $inicio]) {
            $trabajador = DB::table('Personal.Trabajador')->insertGetId([
                'TipoDocumentoIdentidadId' => $tipo, 'ProfesionId' => $profesion, 'TrabajadorNumeroDocumento' => $documento,
                'TrabajadorNombres' => $nombres, 'TrabajadorApellidoPaterno' => $paterno, 'TrabajadorApellidoMaterno' => $materno,
                'TrabajadorSexo' => $sexo, 'TrabajadorFechaNacimiento' => $nacimiento, 'TrabajadorDireccion' => 'Av. Prueba '.substr($documento, -2).', Trujillo',
                'TrabajadorFechaRegistro' => '2026-09-01 08:00:00', 'TrabajadorEstado' => 1,
            ], 'TrabajadorId');
            DB::table('Personal.VinculoLaboral')->insert([
                'TrabajadorId' => $trabajador, 'EessId' => $eess, 'CargoId' => $cargo, 'CondicionLaboralId' => $condicion, 'RegimenLaboralId' => $regimen,
                'VinculoLaboralCodigo' => $codigo, 'VinculoLaboralCodigoAirhsp' => $airhsp, 'VinculoLaboralNumeroPlaza' => $plaza, 'VinculoLaboralFechaInicio' => $inicio,
            ]);
        }
    }

    private function programacionDeTrabajadoresYTurnos(): void
    {
        if (DB::table('Programacion.ProgramacionTrabajador')->exists()) {
            return;
        }
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $turnos = $this->ids('Configuracion.Turno', 'TurnoCodigo', 'TurnoId');
        $periodo = fn (string $eess, string $inicio) => (int) DB::table('Programacion.ProgramacionPeriodo as p')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'p.EessId')
            ->where('e.EessCodigo', $eess)->whereDate('p.ProgramacionPeriodoFechaInicio', $inicio)->value('p.ProgramacionPeriodoId');

        // [periodo, vinculo, estado, observacion|null, turnos: [fecha, turno, estado, guardia]]
        $M = 'M';
        $T = 'T';
        $programaciones = [
            // Setiembre ya esta cerrado: lo programado se cumplio.
            [$periodo('EESS-LE-01', '2026-09-01'), 'VL-0001', 'CERRADA', null, [
                ['2026-09-28', $M, 'CUMPLIDO', 0], ['2026-09-29', $M, 'CUMPLIDO', 0], ['2026-09-30', $T, 'CUMPLIDO', 0],
            ]],
            // Octubre, publicada: tres enfermeros con rotacion de manana y tarde y alguna guardia de 12 horas (RIT, Art. 16 y 20).
            [$periodo('EESS-LE-01', '2026-10-01'), 'VL-0001', 'PUBLICADA', 'Incluye una guardia diurna el sábado 17', [
                ['2026-10-01', $T, 'CUMPLIDO', 0], ['2026-10-02', $M, 'CUMPLIDO', 0], ['2026-10-05', $M, 'PROGRAMADO', 0], ['2026-10-06', $T, 'PROGRAMADO', 0],
                ['2026-10-07', $M, 'PROGRAMADO', 0], ['2026-10-08', $T, 'PROGRAMADO', 0], ['2026-10-09', $M, 'PROGRAMADO', 0], ['2026-10-12', $M, 'PROGRAMADO', 0],
                ['2026-10-13', $T, 'PROGRAMADO', 0], ['2026-10-14', $M, 'PROGRAMADO', 0], ['2026-10-15', $T, 'PROGRAMADO', 0], ['2026-10-16', $M, 'PROGRAMADO', 0],
                ['2026-10-17', 'G12-D', 'PROGRAMADO', 1],
            ]],
            [$periodo('EESS-LE-01', '2026-10-01'), 'VL-0015', 'PUBLICADA', 'Guardia nocturna el sábado 10', [
                ['2026-10-01', $M, 'CUMPLIDO', 0], ['2026-10-02', $T, 'CUMPLIDO', 0], ['2026-10-05', $T, 'PROGRAMADO', 0], ['2026-10-06', $M, 'PROGRAMADO', 0],
                ['2026-10-07', $T, 'PROGRAMADO', 0], ['2026-10-08', $M, 'PROGRAMADO', 0], ['2026-10-09', $T, 'PROGRAMADO', 0], ['2026-10-10', 'N', 'PROGRAMADO', 1],
                ['2026-10-12', $T, 'PROGRAMADO', 0], ['2026-10-13', $M, 'PROGRAMADO', 0], ['2026-10-14', $T, 'PROGRAMADO', 0], ['2026-10-15', $M, 'PROGRAMADO', 0],
            ]],
            [$periodo('EESS-LE-01', '2026-10-01'), 'VL-0016', 'PUBLICADA', null, [
                ['2026-10-05', $M, 'PROGRAMADO', 0], ['2026-10-06', $M, 'PROGRAMADO', 0], ['2026-10-07', $M, 'PROGRAMADO', 0], ['2026-10-08', $T, 'PROGRAMADO', 0],
                ['2026-10-09', $M, 'PROGRAMADO', 0], ['2026-10-14', $M, 'PROGRAMADO', 0], ['2026-10-15', $T, 'PROGRAMADO', 0], ['2026-10-16', $T, 'PROGRAMADO', 0],
            ]],
            // Borradores: se siguen editando.
            [$periodo('EESS-EP-01', '2026-10-01'), 'VL-0002', 'BORRADOR', null, [
                ['2026-10-05', $M, 'PROGRAMADO', 0], ['2026-10-06', $M, 'PROGRAMADO', 0], ['2026-10-07', $M, 'PROGRAMADO', 0], ['2026-10-08', $M, 'PROGRAMADO', 0],
            ]],
            [$periodo('EESS-EP-01', '2026-10-16'), 'VL-0002', 'BORRADOR', 'Falta cargar los turnos de la quincena', []],
            [$periodo('EESS-FM-01', '2026-10-01'), 'VL-0003', 'BORRADOR', null, [
                ['2026-10-01', $M, 'PROGRAMADO', 0], ['2026-10-02', $M, 'PROGRAMADO', 0], ['2026-10-05', $T, 'PROGRAMADO', 0], ['2026-10-06', $T, 'PROGRAMADO', 0],
            ]],
            // Campania extramural publicada: el medico destacado cubre dos dias.
            [$periodo('EESS-LA-01', '2026-10-10'), 'VL-0013', 'PUBLICADA', 'Campaña de vacunación', [
                ['2026-10-10', $M, 'PROGRAMADO', 0], ['2026-10-11', $M, 'PROGRAMADO', 0],
            ]],
            // La programacion anulada arrastra a sus trabajadores y a sus turnos.
            [$periodo('SEDE-RRHH', '2026-10-01'), 'VL-0008', 'ANULADA', null, [
                ['2026-10-05', 'ADM-D', 'ANULADO', 0], ['2026-10-06', 'ADM-D', 'ANULADO', 0],
            ]],
        ];

        foreach ($programaciones as [$periodoId, $vinculo, $estado, $observacion, $dias]) {
            $id = DB::table('Programacion.ProgramacionTrabajador')->insertGetId([
                'ProgramacionPeriodoId' => $periodoId, 'VinculoLaboralId' => $vinculos[$vinculo],
                'ProgramacionTrabajadorObservacion' => $observacion, 'ProgramacionTrabajadorEstado' => $estado,
            ], 'ProgramacionTrabajadorId');
            foreach ($dias as [$fecha, $turno, $estadoTurno, $guardia]) {
                DB::table('Programacion.TurnoProgramado')->insert([
                    'ProgramacionTrabajadorId' => $id, 'TurnoId' => $turnos[$turno], 'TurnoProgramadoFecha' => $fecha,
                    'TurnoProgramadoEsGuardia' => $guardia, 'TurnoProgramadoEstado' => $estadoTurno,
                ]);
            }
        }
        ProgramacionTrabajador::query()->get()->each->recalcularHoras();
    }

    private function ajustesDeMarcacion(): void
    {
        if (DB::table('Asistencia.AjusteMarcacion')->exists()) {
            return;
        }
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');
        $marcacion = fn (string $vinculo, string $fechaHora, string $tipo) => DB::table('Asistencia.Marcacion')
            ->where(['VinculoLaboralId' => $vinculos[$vinculo], 'MarcacionFechaHora' => $fechaHora, 'MarcacionTipo' => $tipo])->value('MarcacionId');

        // [vinculo, hora actual, tipo, hora nueva|null (= invalidar), usuario, documento|null, motivo, estado, fecha de la solicitud]
        $ajustes = [
            ['VL-0004', '2026-09-28 07:41:00', 'ENTRADA', '2026-09-28 07:34:00', 'pgutierrez', null, 'El reloj biométrico tenía la hora adelantada: el trabajador ingresó a las 07:34', 'PENDIENTE', '2026-09-29 09:00:00'],
            ['VL-0010', '2026-09-29 18:58:00', 'ENTRADA', null, 'rvargas', null, 'Marcación duplicada por doble lectura del reloj: se invalida', 'PENDIENTE', '2026-09-30 08:30:00'],
            ['VL-0013', '2026-09-30 13:35:00', 'SALIDA', '2026-09-30 13:30:00', 'pgutierrez', 'certificado-medico-0001.pdf', 'El parte diario consignó 13:35 y el responsable constató la salida a las 13:30', 'APROBADO', '2026-10-01 10:00:00'],
            ['VL-0002', '2026-09-28 13:40:00', 'SALIDA', '2026-09-28 14:00:00', 'crojas', null, 'Pide alargar su salida de la mañana', 'RECHAZADO', '2026-09-29 11:00:00'],
            ['VL-0001', '2026-09-28 07:28:00', 'ENTRADA', '2026-09-28 07:30:00', 'mquispe', null, 'Registrada por error', 'ANULADO', '2026-09-29 12:00:00'],
        ];
        $aprobar = [];
        foreach ($ajustes as [$vinculo, $actual, $tipo, $nueva, $usuario, $documento, $motivo, $estado, $solicitud]) {
            $id = DB::table('Asistencia.AjusteMarcacion')->insertGetId([
                'MarcacionId' => $marcacion($vinculo, $actual, $tipo), 'UsuarioId' => $usuarios[$usuario],
                'DocumentoSustentoId' => $documento === null ? null : $documentos[$documento],
                'AjusteMarcacionFechaHora' => $solicitud, 'AjusteMarcacionFechaHoraAnterior' => $actual, 'AjusteMarcacionFechaHoraNueva' => $nueva,
                'AjusteMarcacionMotivo' => $motivo, 'AjusteMarcacionEstado' => in_array($estado, ['APROBADO', 'RECHAZADO'], true) ? 'PENDIENTE' : $estado,
            ], 'AjusteMarcacionId');
            if (in_array($estado, ['APROBADO', 'RECHAZADO'], true)) {
                $aprobar[] = [$id, $estado];
            }
        }
        // Resolver pasa por el servicio: el aprobado corrige la marcacion y el rechazado deja el motivo.
        $servicio = app(AjusteMarcacionService::class);
        foreach ($aprobar as [$id, $estado]) {
            $ajuste = AjusteMarcacion::query()->findOrFail($id);
            $estado === 'APROBADO'
                ? $servicio->aprobar($ajuste, $usuarios['pgutierrez'], 'Constatado con el responsable de control de asistencia')
                : $servicio->rechazar($ajuste, $usuarios['pgutierrez'], 'La salida corresponde a la hora marcada: no procede el cambio');
        }
    }

    /**
     * Los consolidados de agosto (periodo cerrado) se sembraron solo con sus totales: aqui se les da el detalle diario que
     * los explica (foto de la asistencia), para que total y detalle coincidan. Setiembre ya trae el suyo al generarse.
     */
    private function detalleDeConsolidadosCerrados(): void
    {
        if (DB::table('Consolidacion.DetalleConsolidado')->whereIn('ConsolidadoAsistenciaId', ConsolidadoAsistencia::query()
            ->whereHas('periodo', fn ($p) => $p->where('PeriodoAsistenciaMes', 8))->select('ConsolidadoAsistenciaId'))->exists()) {
            return;
        }
        $agosto = ConsolidadoAsistencia::query()->whereHas('periodo', fn ($p) => $p->where('PeriodoAsistenciaMes', 8))->orderBy('ConsolidadoAsistenciaId')->get();
        foreach ($agosto as $consolidado) {
            $trabajados = (int) $consolidado->ConsolidadoAsistenciaDiasTrabajados;
            $faltas = (int) $consolidado->ConsolidadoAsistenciaDiasFalta;
            $justificadas = (int) $consolidado->ConsolidadoAsistenciaDiasFaltaJustificada;
            // Estados de cada dia habil de agosto (lunes a viernes), uno tras otro.
            $estados = array_merge(array_fill(0, $trabajados, 'ASISTIO'), array_fill(0, $faltas, 'FALTA'), array_fill(0, $justificadas, 'FALTA_JUST'));
            $tardanza = $consolidado->ConsolidadoAsistenciaMinutosTardanza;
            $extra = $consolidado->ConsolidadoAsistenciaMinutosExtra;
            if ($tardanza > 0) {
                $estados[0] = 'TARDANZA';   // la tardanza cae en un dia trabajado
            }
            $dia = strtotime('2026-08-03');
            foreach ($estados as $i => $estado) {
                while (in_array(date('N', $dia), ['6', '7'], true)) {
                    $dia = strtotime('+1 day', $dia);
                }
                DB::table('Consolidacion.DetalleConsolidado')->insert([
                    'ConsolidadoAsistenciaId' => $consolidado->ConsolidadoAsistenciaId, 'DetalleConsolidadoFecha' => date('Y-m-d', $dia),
                    'DetalleConsolidadoEstado' => $estado,
                    'DetalleConsolidadoMinutosTardanza' => $i === 0 ? $tardanza : 0,
                    'DetalleConsolidadoMinutosExtra' => $i === min(1, count($estados) - 1) ? $extra : 0,
                    'DetalleConsolidadoEsJustificada' => $estado === 'FALTA_JUST' ? 1 : 0,
                ]);
                $dia = strtotime('+1 day', $dia);
            }
        }
    }

    /** Liquidaciones de los consolidados cerrados de agosto: una remitida a planilla, una aprobada y una por completar. */
    private function liquidaciones(): void
    {
        if (DB::table('Compensaciones.LiquidacionDescuento')->exists()) {
            return;
        }
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $servicio = app(LiquidacionDescuentoService::class);
        $consolidadoDe = fn (string $vinculo) => ConsolidadoAsistencia::query()
            ->where('VinculoLaboralId', $vinculos[$vinculo])->whereHas('periodo', fn ($p) => $p->where('PeriodoAsistenciaMes', 8))->firstOrFail();

        // Importes de planilla (ficticios): valor del dia 100.00, de la hora 12.50 (jornada de 8 horas) (RIT, Art. 25).
        $remitida = $servicio->generar($consolidadoDe('VL-0003'));
        foreach ($remitida->detalles()->get() as $linea) {
            $linea->update(['DetalleLiquidacionImporte' => round($linea->DetalleLiquidacionCantidad * ($linea->ConceptoDescuentoId === $this->concepto('DESC_FALTA') ? 100 : 12.5), 2)]);
        }
        $servicio->remitir($servicio->aprobar($remitida->fresh()));

        $aprobada = $servicio->generar($consolidadoDe('VL-0006'));
        foreach ($aprobada->detalles()->get() as $linea) {
            $linea->update(['DetalleLiquidacionImporte' => round($linea->DetalleLiquidacionCantidad * 12.5, 2)]);
        }
        $servicio->aprobar($aprobada->fresh());

        $servicio->generar($consolidadoDe('VL-0002'));   // GENERADA: las lineas esperan su importe
    }

    private function concepto(string $codigo): int
    {
        return (int) DB::table('Compensaciones.ConceptoDescuento')->where('ConceptoDescuentoCodigo', $codigo)->value('ConceptoDescuentoId');
    }

    private function periodoVacacional(string $vinculo, int $anio): int
    {
        return (int) DB::table('Vacaciones.PeriodoVacacional as p')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'p.VinculoLaboralId')
            ->where('v.VinculoLaboralCodigo', $vinculo)->where('p.PeriodoVacacionalAnio', $anio)->value('p.PeriodoVacacionalId');
    }

    /** Dias de descanso programados (RIT, Art. 68 a 74): tramos continuos de 7 dias o mas y hasta 7 dias en tramos menores. */
    private function rolVacacional(): void
    {
        if (DB::table('Vacaciones.RolVacacional')->exists()) {
            return;
        }
        // [vinculo, anio, inicio, dias, estado]
        $roles = [
            ['VL-0001', 2025, '2026-03-02', 15, 'GOZADO'],
            ['VL-0001', 2025, '2026-06-01', 5, 'GOZADO'],
            ['VL-0001', 2026, '2026-11-02', 15, 'PROGRAMADO'],
            ['VL-0001', 2026, '2026-12-14', 15, 'PROGRAMADO'],
            ['VL-0001', 2026, '2026-12-01', 15, 'REPROGRAMADO'],
            ['VL-0002', 2026, '2026-08-03', 15, 'GOZADO'],
            ['VL-0005', 2026, '2026-07-20', 10, 'GOZADO'],
            ['VL-0008', 2026, '2027-01-04', 15, 'PROGRAMADO'],
            ['VL-0008', 2026, '2027-02-01', 15, 'PROGRAMADO'],
            ['VL-0008', 2026, '2026-12-01', 10, 'ANULADO'],
        ];
        foreach ($roles as [$vinculo, $anio, $inicio, $dias, $estado]) {
            DB::table('Vacaciones.RolVacacional')->insert([
                'PeriodoVacacionalId' => $this->periodoVacacional($vinculo, $anio), 'RolVacacionalFechaProgramada' => $inicio,
                'RolVacacionalFechaFinProgramada' => date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days')),
                'RolVacacionalDias' => $dias, 'RolVacacionalEstado' => $estado,
            ]);
        }
    }

    private function rolDe(string $vinculo, int $anio, string $inicio): int
    {
        return (int) DB::table('Vacaciones.RolVacacional')->where('PeriodoVacacionalId', $this->periodoVacacional($vinculo, $anio))
            ->whereDate('RolVacacionalFechaProgramada', $inicio)->value('RolVacacionalId');
    }

    /** Goces solicitados: los aprobados explican los dias ya consumidos de cada periodo vacacional. */
    private function gocesVacacionales(): void
    {
        if (DB::table('Vacaciones.GoceVacacional')->exists()) {
            return;
        }
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');
        // [vinculo, anio, inicio del rol, inicio del goce, fin del goce, documento|null, estado]
        $goces = [
            ['VL-0001', 2025, '2026-03-02', '2026-03-02', '2026-03-16', null, 'APROBADO'],
            ['VL-0001', 2025, '2026-06-01', '2026-06-01', '2026-06-05', 'resolucion-comision-0003.pdf', 'APROBADO'],
            ['VL-0002', 2026, '2026-08-03', '2026-08-03', '2026-08-17', null, 'APROBADO'],
            ['VL-0005', 2026, '2026-07-20', '2026-07-20', '2026-07-29', null, 'APROBADO'],
            ['VL-0001', 2026, '2026-11-02', '2026-11-02', '2026-11-16', null, 'PENDIENTE'],
            ['VL-0001', 2026, '2026-12-14', '2026-12-14', '2026-12-20', 'resolucion-comision-0003.pdf', 'RECHAZADO'],
            ['VL-0008', 2026, '2027-01-04', '2027-01-04', '2027-01-10', 'certificado-medico-0001.pdf', 'ANULADO'],
        ];
        foreach ($goces as [$vinculo, $anio, $inicioRol, $inicio, $fin, $documento, $estado]) {
            DB::table('Vacaciones.GoceVacacional')->insert([
                'RolVacacionalId' => $this->rolDe($vinculo, $anio, $inicioRol), 'DocumentoSustentoId' => $documento === null ? null : $documentos[$documento],
                'GoceVacacionalFechaInicio' => $inicio, 'GoceVacacionalFechaFin' => $fin,
                'GoceVacacionalDias' => (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1, 'GoceVacacionalEstado' => $estado,
            ]);
        }
    }

    /**
     * Cambios de turno sobre la programacion publicada de octubre del C.S. La Esperanza (RIT, Art. 16 y 20). Los dos
     * aprobados se aplican con el servicio: la permuta intercambia los turnos y la anulacion deja uno sin efecto.
     */
    private function cambiosDeTurno(): void
    {
        if (DB::table('Programacion.CambioTurno')->exists()) {
            return;
        }
        $vinculos = $this->ids('Personal.VinculoLaboral', 'VinculoLaboralCodigo', 'VinculoLaboralId');
        $usuarios = $this->ids('Seguridad.Usuario', 'UsuarioNombre', 'UsuarioId');
        $documentos = $this->ids('Soporte.DocumentoSustento', 'DocumentoSustentoNombre', 'DocumentoSustentoId');
        $tipos = $this->ids('Programacion.TipoCambioTurno', 'TipoCambioTurnoCodigo', 'TipoCambioTurnoId');
        $turnos = $this->ids('Configuracion.Turno', 'TurnoCodigo', 'TurnoId');
        $turnoDe = fn (string $vinculo, string $fecha) => (int) DB::table('Programacion.TurnoProgramado as t')
            ->join('Programacion.ProgramacionTrabajador as p', 'p.ProgramacionTrabajadorId', '=', 't.ProgramacionTrabajadorId')
            ->where('p.VinculoLaboralId', $vinculos[$vinculo])->whereDate('t.TurnoProgramadoFecha', $fecha)->value('t.TurnoProgramadoId');

        // [tipo, vinculo, fecha del turno, reemplazante|null, contraparte [vinculo, fecha]|null, turno nuevo|null, documento|null, motivo|null, solicitud, estado, resolucion (aprobar|rechazar|null)]
        $cambios = [
            ['REEMPLAZO', 'VL-0001', '2026-10-12', 'VL-0015', null, null, null, 'Cita médica programada con anticipación', '2026-10-02 08:30:00', 'PENDIENTE', null],
            ['REEMPLAZO', 'VL-0001', '2026-10-17', 'VL-0016', null, null, 'resolucion-comision-0003.pdf', 'Capacitación obligatoria el mismo fin de semana', '2026-10-02 09:00:00', 'PENDIENTE', null],
            ['PERMUTA', 'VL-0015', '2026-10-08', 'VL-0016', ['VL-0016', '2026-10-08'], null, null, 'Compromiso familiar: intercambian manana y tarde', '2026-09-30 10:00:00', 'PENDIENTE', 'aprobar'],
            ['REPROGRAMACION', 'VL-0001', '2026-10-14', null, null, 'T', 'resolucion-comision-0003.pdf', 'Se solicita pasar a turno tarde por trámite en la mañana', '2026-09-30 11:30:00', 'PENDIENTE', 'rechazar'],
            ['ANULACION', 'VL-0016', '2026-10-15', null, null, null, 'certificado-medico-0001.pdf', 'Descanso médico programado: el turno queda sin efecto', '2026-10-01 08:00:00', 'PENDIENTE', 'aprobar'],
            ['REEMPLAZO', 'VL-0001', '2026-10-13', 'VL-0016', null, null, null, 'Se desistió del cambio', '2026-10-01 15:00:00', 'ANULADO', null],
        ];
        $resolver = [];
        foreach ($cambios as [$tipo, $vinculo, $fecha, $reemplazante, $contraparte, $nuevo, $documento, $motivo, $solicitud, $estado, $resolucion]) {
            $id = DB::table('Programacion.CambioTurno')->insertGetId([
                'TipoCambioTurnoId' => $tipos[$tipo], 'TurnoProgramadoId' => $turnoDe($vinculo, $fecha),
                'TurnoProgramadoContraparteId' => $contraparte === null ? null : $turnoDe(...$contraparte),
                'TurnoIdNuevo' => $nuevo === null ? null : $turnos[$nuevo],
                'VinculoLaboralSolicitanteId' => $vinculos[$vinculo], 'VinculoLaboralReemplazanteId' => $reemplazante === null ? null : $vinculos[$reemplazante],
                'DocumentoSustentoId' => $documento === null ? null : $documentos[$documento], 'UsuarioRegistroId' => $usuarios['rvargas'],
                'CambioTurnoFechaSolicitud' => $solicitud, 'CambioTurnoMotivo' => $motivo, 'CambioTurnoEstado' => $estado,
            ], 'CambioTurnoId');
            if ($resolucion !== null) {
                $resolver[] = [$id, $resolucion];
            }
        }
        $servicio = app(CambioTurnoService::class);
        foreach ($resolver as [$id, $resolucion]) {
            $cambio = CambioTurno::query()->findOrFail($id);
            $resolucion === 'aprobar'
                ? $servicio->aprobar($cambio, $usuarios['pgutierrez'], 'Autorizado por la jefatura del establecimiento')
                : $servicio->rechazar($cambio, $usuarios['pgutierrez'], 'El turno tarde no está disponible ese día');
        }
    }
}
