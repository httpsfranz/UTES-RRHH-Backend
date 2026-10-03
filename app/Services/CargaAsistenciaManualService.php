<?php

namespace App\Services;

use App\Models\Asistencia\CargaAsistenciaManual;
use App\Models\Asistencia\Marcacion;
use Illuminate\Support\Facades\DB;

class CargaAsistenciaManualService
{
    /**
     * Anular una carga invalida las marcaciones que vinieron de ella (no se borran: quedan como constancia con
     * MarcacionEsValida = 0), todo en una misma transaccion. Anular es idempotente.
     */
    public function anular(CargaAsistenciaManual $carga): int
    {
        return DB::transaction(function () use ($carga) {
            if ($carga->CargaAsistenciaManualEstado !== 'ANULADO') {
                $carga->update(['CargaAsistenciaManualEstado' => 'ANULADO']);
            }

            return Marcacion::query()
                ->where('CargaAsistenciaManualId', $carga->CargaAsistenciaManualId)
                ->where('MarcacionEsValida', 1)
                ->update(['MarcacionEsValida' => 0]);
        });
    }
}
