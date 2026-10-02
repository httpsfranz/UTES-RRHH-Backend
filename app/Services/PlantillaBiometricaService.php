<?php

namespace App\Services;

use App\Models\Biometria\PlantillaBiometrica;
use Illuminate\Support\Facades\DB;

/**
 * Escritura de la plantilla biometrica. La columna es VARBINARY(MAX): el driver de SQL Server enviaria un
 * string como NVARCHAR y rechazaria la conversion implicita, asi que los bytes se mandan en hexadecimal y
 * se convierten en la base (CONVERT(..., 2) = hex sin prefijo).
 */
class PlantillaBiometricaService
{
    /**
     * @param  array<string,mixed>  $datos  columnas fillable de la plantilla
     * @param  string|null  $referenciaBase64  null = no tocar la referencia existente
     */
    public function guardar(?PlantillaBiometrica $plantilla, array $datos, ?string $referenciaBase64): PlantillaBiometrica
    {
        return DB::transaction(function () use ($plantilla, $datos, $referenciaBase64) {
            if ($plantilla) {
                $plantilla->update($datos);
            } else {
                $plantilla = PlantillaBiometrica::create($datos);
            }

            if ($referenciaBase64 !== null) {
                DB::update(
                    'UPDATE Biometria.PlantillaBiometrica SET PlantillaBiometricaReferencia = CONVERT(VARBINARY(MAX), ?, 2) WHERE PlantillaBiometricaId = ?',
                    [bin2hex(base64_decode($referenciaBase64, true)), $plantilla->PlantillaBiometricaId],
                );
            }

            return PlantillaBiometrica::query()->sinReferencia()->findOrFail($plantilla->PlantillaBiometricaId);
        });
    }
}
