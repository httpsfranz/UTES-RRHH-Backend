<?php

namespace Tests\Support;

use App\Models\Programacion\ProgramacionTrabajador;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Escenarios que arman directamente en la base los datos de los que dependen los modulos de programacion, consolidacion,
 * liquidacion y vacaciones (niveles 4 a 6): periodos, programaciones, turnos, consolidados y periodos vacacionales. Se
 * mezcla en CrudModulosTestCase; cada metodo crea filas nuevas (dentro de la transaccion del test) para no chocar con
 * las sembradas.
 */
trait ArmaEscenarios
{
    /** @var array<string,mixed> */
    private array $recordados = [];

    /** Memoriza por test el resultado de $crea: varias FK de una especificacion comparten el mismo escenario. */
    public function recordar(string $clave, Closure $crea): mixed
    {
        return $this->recordados[$clave] ??= $crea();
    }

    protected function idPorCodigo(string $tabla, string $pk, string $columna, string $valor): int
    {
        $id = DB::table($tabla)->where($columna, $valor)->value($pk);
        $this->assertNotNull($id, "Falta la fila sembrada {$tabla} {$columna}={$valor}.");

        return (int) $id;
    }

    public function turnoId(string $codigo): int
    {
        return $this->idPorCodigo('Configuracion.Turno', 'TurnoId', 'TurnoCodigo', $codigo);
    }

    public function usuarioId(string $nombre = 'rvargas'): int
    {
        return $this->idPorCodigo('Seguridad.Usuario', 'UsuarioId', 'UsuarioNombre', $nombre);
    }

    public function eessId(string $codigo = 'EESS-LE-01'): int
    {
        return $this->idPorCodigo('Organizacion.EstablecimientoSalud', 'EessId', 'EessCodigo', $codigo);
    }

    public function documentoId(string $nombre = 'resolucion-comision-0003.pdf'): int
    {
        return $this->idPorCodigo('Soporte.DocumentoSustento', 'DocumentoSustentoId', 'DocumentoSustentoNombre', $nombre);
    }

    /** Vinculo nuevo de enfermero(a) nombrado del D.L. 276 (hace guardia) en el establecimiento indicado. */
    public function nuevoVinculoDeGuardia(array $extra = [], ?int $trabajadorId = null): int
    {
        return $this->nuevoVinculo($extra + [
            'RegimenLaboralId' => $this->idPorCodigo('Personal.RegimenLaboral', 'RegimenLaboralId', 'RegimenLaboralCodigo', 'DL276'),
            'CondicionLaboralId' => $this->idPorCodigo('Personal.CondicionLaboral', 'CondicionLaboralId', 'CondicionLaboralCodigo', 'NOMBRADO'),
            'CargoId' => $this->idPorCodigo('Personal.Cargo', 'CargoId', 'CargoNombre', 'Enfermero(a)'),
            'VinculoLaboralCodigoAirhsp' => (string) random_int(200000, 299999),
        ], $trabajadorId);
    }

    // ------------------------------------------------------------------------------------------ programacion

    /** Programacion de un establecimiento. Por omision: mensual de noviembre de 2026 del C.S. La Esperanza (sin otra sembrada). */
    public function periodoDeProgramacion(string $estado = 'BORRADOR', string $eess = 'EESS-LE-01', string $inicio = '2026-11-01', ?string $fin = null, string $tipo = 'MENSUAL'): int
    {
        return (int) DB::table('Programacion.ProgramacionPeriodo')->insertGetId([
            'EessId' => $this->eessId($eess),
            'TipoPeriodoProgramacionId' => $this->idPorCodigo('Programacion.TipoPeriodoProgramacion', 'TipoPeriodoProgramacionId', 'TipoPeriodoProgramacionCodigo', $tipo),
            'UsuarioRegistroId' => $this->usuarioId(),
            'ProgramacionPeriodoAnio' => (int) substr($inicio, 0, 4),
            'ProgramacionPeriodoMes' => (int) substr($inicio, 5, 2),
            'ProgramacionPeriodoFechaInicio' => $inicio,
            'ProgramacionPeriodoFechaFin' => $fin ?? date('Y-m-t', strtotime($inicio)),
            'ProgramacionPeriodoFechaPublicacion' => $estado === 'BORRADOR' ? null : '2026-10-02 08:00:00',
            'ProgramacionPeriodoEstado' => $estado,
        ], 'ProgramacionPeriodoId');
    }

    /** El trabajador dentro de la programacion; hereda el estado del periodo salvo que se indique otro. */
    public function programacionDeTrabajador(int $periodoId, int $vinculoId, ?string $estado = null): int
    {
        $estadoPeriodo = DB::table('Programacion.ProgramacionPeriodo')->where('ProgramacionPeriodoId', $periodoId)->value('ProgramacionPeriodoEstado');

        return (int) DB::table('Programacion.ProgramacionTrabajador')->insertGetId([
            'ProgramacionPeriodoId' => $periodoId,
            'VinculoLaboralId' => $vinculoId,
            'ProgramacionTrabajadorEstado' => $estado ?? $estadoPeriodo,
        ], 'ProgramacionTrabajadorId');
    }

    /**
     * Turno programado insertado directo (sin pasar por las reglas). Recalcula las horas de la programacion del trabajador.
     *
     * @param  array<string,mixed>  $extra  columnas de Programacion.TurnoProgramado a pisar
     */
    public function turnoProgramado(int $programacionTrabajadorId, string $fecha, string $turno = 'M', array $extra = []): int
    {
        $id = (int) DB::table('Programacion.TurnoProgramado')->insertGetId($extra + [
            'ProgramacionTrabajadorId' => $programacionTrabajadorId,
            'TurnoId' => $this->turnoId($turno),
            'TurnoProgramadoFecha' => $fecha,
            'TurnoProgramadoEsGuardia' => in_array($turno, ['N', 'G12-D'], true) ? 1 : 0,
        ], 'TurnoProgramadoId');
        ProgramacionTrabajador::query()->find($programacionTrabajadorId)->recalcularHoras();

        return $id;
    }

    /**
     * Escenario base de un cambio de turno: programacion de noviembre PUBLICADA del C.S. La Esperanza con dos enfermeros del
     * D.L. 276 (el solicitante y quien lo reemplaza) y un turno de manana del solicitante el 2026-11-10 (con mas de 48 horas
     * de anticipacion respecto de "hoy", 2026-10-02).
     *
     * @return array{periodo:int, solicitante:int, reemplazante:int, programacionSolicitante:int, programacionReemplazante:int, turno:int}
     */
    public function escenarioDeCambioDeTurno(): array
    {
        return $this->recordar('cambio-de-turno', function () {
            $periodo = $this->periodoDeProgramacion('PUBLICADA');
            $solicitante = $this->nuevoVinculoDeGuardia();
            $reemplazante = $this->nuevoVinculoDeGuardia();
            $pt = $this->programacionDeTrabajador($periodo, $solicitante);
            $ptReemplazante = $this->programacionDeTrabajador($periodo, $reemplazante);

            return [
                'periodo' => $periodo, 'solicitante' => $solicitante, 'reemplazante' => $reemplazante,
                'programacionSolicitante' => $pt, 'programacionReemplazante' => $ptReemplazante,
                'turno' => $this->turnoProgramado($pt, '2026-11-10', 'M'),
            ];
        });
    }

    // ------------------------------------------------------------------------------------------ consolidacion y liquidacion

    /** Periodo de asistencia de 2026 por mes (8 cerrado, 9 en proceso, 10 abierto). */
    public function periodoDeAsistenciaId(int $mes): int
    {
        return (int) DB::table('Consolidacion.PeriodoAsistencia')->where(['PeriodoAsistenciaAnio' => 2026, 'PeriodoAsistenciaMes' => $mes])->value('PeriodoAsistenciaId');
    }

    /** @param  array<string,mixed>  $extra  columnas de Consolidacion.ConsolidadoAsistencia a pisar */
    public function consolidado(int $vinculoId, int $mes = 10, array $extra = []): int
    {
        return (int) DB::table('Consolidacion.ConsolidadoAsistencia')->insertGetId($extra + [
            'PeriodoAsistenciaId' => $this->periodoDeAsistenciaId($mes),
            'VinculoLaboralId' => $vinculoId,
        ], 'ConsolidadoAsistenciaId');
    }

    /** Consolidado conforme con 2 dias de falta y 90 minutos de tardanza: tiene algo que liquidar. */
    public function consolidadoLiquidable(int $mes = 10): int
    {
        return $this->consolidado($this->nuevoVinculo(), $mes, [
            'ConsolidadoAsistenciaDiasFalta' => 2, 'ConsolidadoAsistenciaMinutosTardanza' => 90, 'ConsolidadoAsistenciaEstado' => 'CONFORME',
        ]);
    }

    public function liquidacionDeDescuentos(?int $consolidadoId = null, string $estado = 'GENERADO'): int
    {
        return (int) DB::table('Compensaciones.LiquidacionDescuento')->insertGetId([
            'ConsolidadoAsistenciaId' => $consolidadoId ?? $this->consolidadoLiquidable(),
            'LiquidacionDescuentoEstado' => $estado,
        ], 'LiquidacionDescuentoId');
    }

    // ------------------------------------------------------------------------------------------ vacaciones

    /** Periodo vacacional del vinculo (2026-01-01 a 2026-12-31, 30 dias ganados y disponibles). */
    public function periodoVacacional(int $vinculoId, array $extra = []): int
    {
        return (int) DB::table('Vacaciones.PeriodoVacacional')->insertGetId($extra + [
            'VinculoLaboralId' => $vinculoId,
            'PeriodoVacacionalAnio' => 2026,
            'PeriodoVacacionalFechaInicio' => '2026-01-01',
            'PeriodoVacacionalFechaFin' => '2026-12-31',
            'PeriodoVacacionalDiasGanados' => 30,
            'PeriodoVacacionalDiasDisponibles' => 30,
        ], 'PeriodoVacacionalId');
    }

    public function rolVacacional(int $periodoVacacionalId, string $inicio = '2026-12-07', int $dias = 15, string $estado = 'PROGRAMADO'): int
    {
        return (int) DB::table('Vacaciones.RolVacacional')->insertGetId([
            'PeriodoVacacionalId' => $periodoVacacionalId,
            'RolVacacionalFechaProgramada' => $inicio,
            'RolVacacionalFechaFinProgramada' => date('Y-m-d', strtotime("{$inicio} +".($dias - 1).' days')),
            'RolVacacionalDias' => $dias,
            'RolVacacionalEstado' => $estado,
        ], 'RolVacacionalId');
    }
}
