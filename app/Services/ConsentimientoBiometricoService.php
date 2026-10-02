<?php

namespace App\Services;

use App\Models\Biometria\ConsentimientoBiometrico;
use App\Models\Biometria\PlantillaBiometrica;
use Illuminate\Support\Facades\DB;

/**
 * Registro de consentimientos biometricos. Revocar el consentimiento obliga a dejar de usar los datos
 * biometricos del trabajador: sus plantillas activas se desactivan en la MISMA transaccion.
 */
class ConsentimientoBiometricoService
{
    /**
     * @param  array{TrabajadorId: int, DocumentoSustentoId?: int|null, ConsentimientoBiometricoAceptado: bool, ConsentimientoBiometricoVersion?: string|null}  $datos
     * @return array{consentimiento: ConsentimientoBiometrico, plantillas_desactivadas: int}
     */
    public function registrar(array $datos): array
    {
        return DB::transaction(function () use ($datos) {
            $consentimiento = ConsentimientoBiometrico::create($datos);

            $desactivadas = 0;
            if (! $consentimiento->ConsentimientoBiometricoAceptado) {
                $desactivadas = PlantillaBiometrica::query()
                    ->where('TrabajadorId', $consentimiento->TrabajadorId)
                    ->where('PlantillaBiometricaEstado', 1)
                    ->update(['PlantillaBiometricaEstado' => 0]);
            }

            return ['consentimiento' => $consentimiento->fresh(), 'plantillas_desactivadas' => $desactivadas];
        });
    }
}
