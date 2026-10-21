<?php

namespace App\Services;

use App\Models\Asistencia\CargaAsistenciaManual;
use App\Models\Asistencia\Marcacion;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CargaAsistenciaManualService
{
    public function __construct(private readonly CalculoAsistenciaService $calculo) {}

    /**
     * Anular una carga invalida las marcaciones que vinieron de ella (no se borran: quedan como constancia con
     * MarcacionEsValida = 0), todo en una misma transaccion, y recalcula la asistencia de esos dias para que el
     * calendario deje de contarlas. Anular es idempotente.
     */
    public function anular(CargaAsistenciaManual $carga): int
    {
        $marcas = Marcacion::query()->where('CargaAsistenciaManualId', $carga->CargaAsistenciaManualId)->where('MarcacionEsValida', 1);
        $afectadas = (clone $marcas)->get(['VinculoLaboralId', 'MarcacionFechaHora']);

        $anuladas = DB::transaction(function () use ($carga, $marcas) {
            if ($carga->CargaAsistenciaManualEstado !== 'ANULADO') {
                $carga->update(['CargaAsistenciaManualEstado' => 'ANULADO']);
            }

            return $marcas->update(['MarcacionEsValida' => 0]);
        });

        foreach ($afectadas->groupBy('VinculoLaboralId') as $vinculo => $delVinculo) {
            $fechas = $delVinculo->map(fn ($m) => substr((string) $m->MarcacionFechaHora, 0, 10));
            // La salida de una guardia nocturna pertenece al dia anterior.
            $desde = CarbonImmutable::parse($fechas->min())->subDay()->toDateString();
            $this->calculo->calcular($desde, min($fechas->max(), HoraLocal::hoy()->toDateString()), (int) $vinculo);
        }

        return $anuladas;
    }
}
