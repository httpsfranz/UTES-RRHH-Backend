<?php

namespace App\Services;

use App\Models\Asistencia\AsistenciaDiaria;
use App\Models\Consolidacion\ConsolidadoAsistencia;
use App\Models\Consolidacion\DetalleConsolidado;
use App\Models\Consolidacion\PeriodoAsistencia;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Consolidado mensual a partir de la asistencia diaria (PHP primero; un procedimiento almacenado solo si se mide que
 * hace falta, segun el manual de desarrollo). Por cada vinculo con asistencia en el periodo:
 *   dias trabajados          = dias con estado no-falta y laborable (EsFalta = 0 y EsLaborable = 1)
 *   dias de falta            = dias con falta descontable                (EsFalta = 1 y EsDescontable = 1)
 *   dias de falta justificada = faltas no descontables                  (EsFalta = 1 y EsDescontable = 0)
 *   minutos de tardanza y de sobretiempo = suma de la asistencia diaria.
 * Cada consolidado guarda ademas el detalle de sus dias (Consolidacion.DetalleConsolidado): la foto de la asistencia
 * diaria al momento de generarlo, que no cambia aunque despues se corrija la asistencia.
 */
class ConsolidadoAsistenciaService
{
    /**
     * Genera o regenera los consolidados del periodo. Los CONFORME y CERRADO no se tocan (se cuentan como omitidos).
     *
     * @return array{generados: int, actualizados: int, omitidos: int}
     */
    public function generar(PeriodoAsistencia $periodo, ?int $vinculoId = null, ?int $eessId = null): array
    {
        if ($periodo->PeriodoAsistenciaEstado === 'CERRADO') {
            throw new DomainException('El período de asistencia está cerrado: ya no se generan consolidados.');
        }

        return DB::transaction(function () use ($periodo, $vinculoId, $eessId) {
            $filas = AsistenciaDiaria::query()
                ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'Asistencia.AsistenciaDiaria.EstadoAsistenciaId')
                ->join('Personal.VinculoLaboral as vl', 'vl.VinculoLaboralId', '=', 'Asistencia.AsistenciaDiaria.VinculoLaboralId')
                ->whereDate('AsistenciaDiariaFecha', '>=', $periodo->PeriodoAsistenciaFechaInicio->toDateString())
                ->whereDate('AsistenciaDiariaFecha', '<=', $periodo->PeriodoAsistenciaFechaFin->toDateString())
                ->when($vinculoId, fn ($q) => $q->where('Asistencia.AsistenciaDiaria.VinculoLaboralId', $vinculoId))
                ->when($eessId, fn ($q) => $q->where('vl.EessId', $eessId))
                ->groupBy('Asistencia.AsistenciaDiaria.VinculoLaboralId')
                ->selectRaw('Asistencia.AsistenciaDiaria.VinculoLaboralId AS VinculoLaboralId')
                ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 0 AND ea.EstadoAsistenciaEsLaborable = 1 THEN 1 ELSE 0 END) AS Trabajados')
                ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 1 THEN 1 ELSE 0 END) AS Faltas')
                ->selectRaw('SUM(CASE WHEN ea.EstadoAsistenciaEsFalta = 1 AND ea.EstadoAsistenciaEsDescontable = 0 THEN 1 ELSE 0 END) AS Justificadas')
                ->selectRaw('SUM(AsistenciaDiariaMinutosTardanza) AS Tardanza')
                ->selectRaw('SUM(AsistenciaDiariaMinutosExtra) AS Extra')
                ->get();

            $generados = $actualizados = $omitidos = 0;
            foreach ($filas as $fila) {
                $datos = [
                    'ConsolidadoAsistenciaDiasTrabajados' => (float) $fila->Trabajados,
                    'ConsolidadoAsistenciaDiasFalta' => (float) $fila->Faltas,
                    'ConsolidadoAsistenciaDiasFaltaJustificada' => (float) $fila->Justificadas,
                    'ConsolidadoAsistenciaMinutosTardanza' => (int) $fila->Tardanza,
                    'ConsolidadoAsistenciaMinutosExtra' => (int) $fila->Extra,
                    'ConsolidadoAsistenciaFechaGeneracion' => now(),
                ];
                $existente = ConsolidadoAsistencia::query()
                    ->where('PeriodoAsistenciaId', $periodo->PeriodoAsistenciaId)
                    ->where('VinculoLaboralId', $fila->VinculoLaboralId)
                    ->first();

                if ($existente === null) {
                    $consolidado = ConsolidadoAsistencia::create($datos + [
                        'PeriodoAsistenciaId' => $periodo->PeriodoAsistenciaId, 'VinculoLaboralId' => $fila->VinculoLaboralId, 'ConsolidadoAsistenciaEstado' => 'GENERADO',
                    ]);
                    $this->fotografiarDias($consolidado, $periodo);
                    $generados++;
                } elseif (in_array($existente->ConsolidadoAsistenciaEstado, ['CONFORME', 'CERRADO'], true)) {
                    $omitidos++;
                } else {
                    $existente->update($datos);
                    $this->fotografiarDias($existente, $periodo);
                    $actualizados++;
                }
            }

            return ['generados' => $generados, 'actualizados' => $actualizados, 'omitidos' => $omitidos];
        });
    }

    /**
     * Reemplaza el detalle del consolidado por la foto actual de la asistencia diaria del periodo: un registro por dia,
     * con el codigo del estado, los minutos y si la falta esta justificada (falta no descontable).
     */
    private function fotografiarDias(ConsolidadoAsistencia $consolidado, PeriodoAsistencia $periodo): void
    {
        DetalleConsolidado::query()->where('ConsolidadoAsistenciaId', $consolidado->ConsolidadoAsistenciaId)->delete();

        $dias = AsistenciaDiaria::query()
            ->join('Asistencia.EstadoAsistencia as ea', 'ea.EstadoAsistenciaId', '=', 'Asistencia.AsistenciaDiaria.EstadoAsistenciaId')
            ->where('Asistencia.AsistenciaDiaria.VinculoLaboralId', $consolidado->VinculoLaboralId)
            ->whereDate('AsistenciaDiariaFecha', '>=', $periodo->PeriodoAsistenciaFechaInicio->toDateString())
            ->whereDate('AsistenciaDiariaFecha', '<=', $periodo->PeriodoAsistenciaFechaFin->toDateString())
            ->orderBy('AsistenciaDiariaFecha')
            ->get(['AsistenciaDiariaId', 'AsistenciaDiariaFecha', 'AsistenciaDiariaMinutosTardanza', 'AsistenciaDiariaMinutosExtra', 'ea.EstadoAsistenciaCodigo', 'ea.EstadoAsistenciaEsFalta', 'ea.EstadoAsistenciaEsDescontable']);

        foreach ($dias as $dia) {
            DetalleConsolidado::create([
                'ConsolidadoAsistenciaId' => $consolidado->ConsolidadoAsistenciaId,
                'AsistenciaDiariaId' => $dia->AsistenciaDiariaId,
                'DetalleConsolidadoFecha' => $dia->AsistenciaDiariaFecha,
                'DetalleConsolidadoEstado' => $dia->EstadoAsistenciaCodigo,
                'DetalleConsolidadoMinutosTardanza' => (int) $dia->AsistenciaDiariaMinutosTardanza,
                'DetalleConsolidadoMinutosExtra' => (int) $dia->AsistenciaDiariaMinutosExtra,
                'DetalleConsolidadoEsJustificada' => (bool) $dia->EstadoAsistenciaEsFalta && ! $dia->EstadoAsistenciaEsDescontable,
            ]);
        }
    }

    /** Un consolidado CONFORME o CERRADO ya se presento: no se elimina. */
    public function eliminar(ConsolidadoAsistencia $consolidado): void
    {
        if (in_array($consolidado->ConsolidadoAsistenciaEstado, ['CONFORME', 'CERRADO'], true)) {
            throw new DomainException('No se puede eliminar un consolidado conforme o cerrado.');
        }
        if ($consolidado->periodo?->PeriodoAsistenciaEstado === 'CERRADO') {
            throw new DomainException('El período de asistencia está cerrado: no se eliminan consolidados.');
        }
        DB::transaction(function () use ($consolidado) {
            DetalleConsolidado::query()->where('ConsolidadoAsistenciaId', $consolidado->ConsolidadoAsistenciaId)->delete();
            $consolidado->delete();
        });
    }
}
