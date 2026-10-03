<?php

namespace App\Services;

use App\Models\Disciplina\ExpedientePad;
use DomainException;

class ExpedientePadService
{
    /**
     * Anular un expediente abierto por error. Un expediente ya resuelto o archivado es la constancia de un procedimiento
     * que si ocurrio, y no se anula. Anular es idempotente.
     */
    public function anular(ExpedientePad $expediente): ExpedientePad
    {
        if (in_array($expediente->ExpedientePadEstado, ['RESUELTO', 'ARCHIVADO'], true)) {
            throw new DomainException('No se puede anular un expediente resuelto o archivado.');
        }
        if ($expediente->ExpedientePadEstado !== 'ANULADO') {
            $expediente->update(['ExpedientePadEstado' => 'ANULADO']);
        }

        return $expediente->fresh();
    }
}
