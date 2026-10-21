<?php

namespace App\Services;

use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\PeriodoAsistencia;
use App\Models\Seguridad\Usuario;
use App\Support\AlcanceDeEess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Cierre del mes": en que punto esta el periodo de asistencia y que falta para cerrarlo, en el orden en que se hace:
 *   1. resolver las solicitudes pendientes del mes (justificaciones, papeletas...: cambian la asistencia);
 *   2. generar el consolidado de cada trabajador;
 *   3. revisarlo y darle conformidad (los que no tienen faltas ni tardanzas se pueden aprobar juntos);
 *   4. generar la liquidacion de descuentos de quien tiene faltas o tardanzas;
 *   5. cerrar el periodo (para toda la Red: exige que todo consolidado tenga conformidad).
 * Reune contadores; cada accion sigue siendo la de su modulo.
 */
class CierreDelMesService
{
    public function __construct(private readonly BandejaDeSolicitudesService $bandeja, private readonly RevisionConsolidadoService $revision) {}

    /**
     * @param  list<int>|null  $eessIds  establecimientos a mirar (null = toda la Red)
     * @return array<string,mixed>
     */
    public function resumen(PeriodoAsistencia $periodo, Usuario $usuario, ?array $eessIds): array
    {
        $desde = $periodo->PeriodoAsistenciaFechaInicio->toDateString();
        $hasta = $periodo->PeriodoAsistenciaFechaFin->toDateString();

        $pendientes = collect($this->bandeja->listar($usuario)['solicitudes'])
            ->filter(fn (array $s) => $s['fecha'] <= $hasta && ($s['fecha_fin'] ?? $s['fecha']) >= $desde && ($eessIds === null || in_array($s['eess_id'], $eessIds, true)));

        $consolidados = $this->consolidados($periodo, $eessIds)->select('c.ConsolidadoAsistenciaEstado as estado', DB::raw('COUNT(*) as n'))
            ->groupBy('c.ConsolidadoAsistenciaEstado')->pluck('n', 'estado')->map(fn ($n) => (int) $n);
        $conAsistencia = DB::table('Asistencia.AsistenciaDiaria as a')->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'a.VinculoLaboralId')
            ->whereDate('a.AsistenciaDiariaFecha', '>=', $desde)->whereDate('a.AsistenciaDiariaFecha', '<=', $hasta)
            ->when($eessIds !== null, fn (Builder $q) => $q->whereIn('v.EessId', $eessIds ?: [0]))
            ->distinct()->count('a.VinculoLaboralId');

        $conDescuento = $this->consolidados($periodo, $eessIds)->whereIn('c.ConsolidadoAsistenciaEstado', LiquidacionDescuentoService::CONSOLIDADOS_LIQUIDABLES)
            ->where(fn (Builder $q) => $q->where('c.ConsolidadoAsistenciaDiasFalta', '>', 0)->orWhere('c.ConsolidadoAsistenciaMinutosTardanza', '>', 0));
        $liquidaciones = (clone $conDescuento)->join('Compensaciones.LiquidacionDescuento as l', 'l.ConsolidadoAsistenciaId', '=', 'c.ConsolidadoAsistenciaId')
            ->where('l.LiquidacionDescuentoEstado', '<>', 'ANULADO')
            ->select('l.LiquidacionDescuentoEstado as estado', DB::raw('COUNT(*) as n'))->groupBy('l.LiquidacionDescuentoEstado')->pluck('n', 'estado')->map(fn ($n) => (int) $n);

        // El cierre es de toda la Red: lo que falta se cuenta sin filtrar por establecimiento.
        $sinConformidadEnLaRed = DB::table('Consolidacion.ConsolidadoAsistencia')->where('PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)
            ->whereIn('ConsolidadoAsistenciaEstado', ['GENERADO', 'OBSERVADO'])->count();
        $cerrado = $periodo->PeriodoAsistenciaEstado === 'CERRADO';

        return [
            'periodo' => [
                'id' => (int) $periodo->PeriodoAsistenciaId, 'anio' => (int) $periodo->PeriodoAsistenciaAnio, 'mes' => (int) $periodo->PeriodoAsistenciaMes,
                'etiqueta' => CalendarioAsistenciaService::MESES[(int) $periodo->PeriodoAsistenciaMes].' '.$periodo->PeriodoAsistenciaAnio,
                'desde' => $desde, 'hasta' => $hasta, 'estado' => $periodo->PeriodoAsistenciaEstado, 'fecha_cierre' => $periodo->PeriodoAsistenciaFechaCierre?->format('Y-m-d H:i'),
            ],
            'solicitudes_pendientes' => $pendientes->count(),
            'trabajadores_con_asistencia' => $conAsistencia,
            'consolidados' => [
                'total' => $consolidados->sum(), 'generado' => $consolidados->get('GENERADO', 0), 'observado' => $consolidados->get('OBSERVADO', 0),
                'conforme' => $consolidados->get('CONFORME', 0), 'cerrado' => $consolidados->get('CERRADO', 0),
                'sin_incidencias' => $this->sinIncidencias($periodo, $eessIds)->count(),
            ],
            'liquidaciones' => [
                'por_generar' => (clone $conDescuento)->count() - $liquidaciones->sum(), 'generado' => $liquidaciones->get('GENERADO', 0),
                'aprobado' => $liquidaciones->get('APROBADO', 0), 'remitido' => $liquidaciones->get('REMITIDO', 0),
            ],
            'cierre' => [
                'cerrado' => $cerrado, 'sin_conformidad_en_la_red' => $sinConformidadEnLaRed,
                'puede_cerrar' => ! $cerrado && $sinConformidadEnLaRed === 0 && $consolidados->sum() > 0,
            ],
        ];
    }

    /**
     * Da conformidad a la vez a los consolidados GENERADOS que no tienen ninguna incidencia (faltas, tardanzas ni salidas
     * anticipadas): son los que no hace falta revisar uno por uno. Los demas se revisan en el consolidado.
     *
     * @param  list<int>|null  $eessIds
     */
    public function conformidadSinIncidencias(PeriodoAsistencia $periodo, ?array $eessIds, Usuario $revisor): int
    {
        $ids = $this->sinIncidencias($periodo, $eessIds)->pluck('c.ConsolidadoAsistenciaId');

        return DB::transaction(function () use ($ids, $revisor) {
            ConsolidadoAsistencia::query()->whereIn('ConsolidadoAsistenciaId', $ids)->get()
                ->each(fn (ConsolidadoAsistencia $c) => $this->revision->aprobar($c, $revisor, 'Conforme: sin faltas, tardanzas ni salidas anticipadas en el mes.'));

            return $ids->count();
        });
    }

    /**
     * Genera de una vez la liquidacion de descuentos de cada consolidado con conformidad que tiene faltas o tardanzas y
     * aun no la tiene (o la tuvo anulada). Devuelve cuantas genero.
     *
     * @param  list<int>|null  $eessIds
     */
    public function generarLiquidaciones(PeriodoAsistencia $periodo, ?array $eessIds, LiquidacionDescuentoService $liquidaciones): int
    {
        $ids = $this->consolidados($periodo, $eessIds)->whereIn('c.ConsolidadoAsistenciaEstado', LiquidacionDescuentoService::CONSOLIDADOS_LIQUIDABLES)
            ->where(fn (Builder $q) => $q->where('c.ConsolidadoAsistenciaDiasFalta', '>', 0)->orWhere('c.ConsolidadoAsistenciaMinutosTardanza', '>', 0))
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from('Compensaciones.LiquidacionDescuento as l')
                ->whereColumn('l.ConsolidadoAsistenciaId', 'c.ConsolidadoAsistenciaId')->where('l.LiquidacionDescuentoEstado', '<>', 'ANULADO'))
            ->pluck('c.ConsolidadoAsistenciaId');

        return DB::transaction(function () use ($ids, $liquidaciones) {
            ConsolidadoAsistencia::query()->whereIn('ConsolidadoAsistenciaId', $ids)->get()->each(fn (ConsolidadoAsistencia $c) => $liquidaciones->generar($c));

            return $ids->count();
        });
    }

    /** @param  list<int>|null  $eessIds */
    private function consolidados(PeriodoAsistencia $periodo, ?array $eessIds): Builder
    {
        return DB::table('Consolidacion.ConsolidadoAsistencia as c')
            ->join('Personal.VinculoLaboral as v', 'v.VinculoLaboralId', '=', 'c.VinculoLaboralId')
            ->where('c.PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)
            ->when($eessIds !== null, fn (Builder $q) => $q->whereIn('v.EessId', $eessIds ?: [0]));
    }

    /**
     * Consolidados por revisar sin incidencias en el mes (misma regla que RevisionConsolidadoService::incidencias).
     *
     * @param  list<int>|null  $eessIds
     */
    private function sinIncidencias(PeriodoAsistencia $periodo, ?array $eessIds): Builder
    {
        $desde = $periodo->PeriodoAsistenciaFechaInicio->toDateString();
        $hasta = $periodo->PeriodoAsistenciaFechaFin->toDateString();

        return $this->consolidados($periodo, $eessIds)->where('c.ConsolidadoAsistenciaEstado', 'GENERADO')
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from('Asistencia.AsistenciaDiaria as a')
                ->join('Asistencia.EstadoAsistencia as e', 'e.EstadoAsistenciaId', '=', 'a.EstadoAsistenciaId')
                ->whereColumn('a.VinculoLaboralId', 'c.VinculoLaboralId')
                ->whereDate('a.AsistenciaDiariaFecha', '>=', $desde)->whereDate('a.AsistenciaDiariaFecha', '<=', $hasta)
                ->where(fn (Builder $s) => $s->where('e.EstadoAsistenciaEsFalta', 1)->orWhere('a.AsistenciaDiariaMinutosTardanza', '>', 0)->orWhere('e.EstadoAsistenciaCodigo', 'SALIDA_ANTIC')));
    }

    /**
     * Establecimientos a mirar: el pedido (si esta en su alcance) o todos los de su alcance.
     *
     * @return list<int>|null
     */
    public static function establecimientos(?int $eessId, Usuario $usuario): ?array
    {
        if ($eessId !== null) {
            AlcanceDeEess::exigir($eessId, $usuario);

            return [$eessId];
        }

        return AlcanceDeEess::permitidos($usuario);
    }
}
