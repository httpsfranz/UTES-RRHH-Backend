<?php

namespace App\Services;

use App\Models\Configuracion\Horario;
use App\Models\Configuracion\HorarioDetalle;
use App\Models\Configuracion\Turno;
use Illuminate\Support\Facades\DB;

/**
 * Grilla semanal de un horario (Configuracion.HorarioDetalle). Toca muchas filas a la vez, asi que va en
 * una transaccion: queda la grilla completa que se pidio o no cambia nada. HorarioDetalle no la
 * referencia ninguna otra tabla, por lo que reemplazarla (borrar + insertar) es seguro.
 */
class HorarioDetalleService
{
    /**
     * Deja al horario con EXACTAMENTE estas filas. El orden de los turnos de un mismo dia, si no se indica,
     * sigue la hora de entrada del turno.
     *
     * @param  list<array{TurnoId: int, HorarioDetalleDia: int, HorarioDetalleOrden?: int|null, HorarioDetalleEsDescanso?: bool|null}>  $filas
     * @return array{filas: int}
     */
    public function sincronizar(Horario $horario, array $filas): array
    {
        $entradas = Turno::query()
            ->whereIn('TurnoId', array_column($filas, 'TurnoId'))
            ->pluck('TurnoHoraEntrada', 'TurnoId')
            ->map(fn ($hora) => substr((string) $hora, 0, 5));

        $porDia = collect($filas)->groupBy('HorarioDetalleDia');

        return DB::transaction(function () use ($horario, $porDia, $entradas) {
            HorarioDetalle::query()->where('HorarioId', $horario->HorarioId)->delete();

            $total = 0;
            foreach ($porDia as $dia => $delDia) {
                // Los que traen orden propio lo conservan; el resto se ubica segun su hora de entrada.
                $ordenados = $delDia->sortBy(fn ($f) => str_pad((string) ($f['HorarioDetalleOrden'] ?? 99), 2, '0', STR_PAD_LEFT).'-'.($entradas[$f['TurnoId']] ?? '99:99'))->values();
                $usados = $ordenados->pluck('HorarioDetalleOrden')->filter()->all();
                $siguiente = 1;

                foreach ($ordenados as $fila) {
                    $orden = $fila['HorarioDetalleOrden'] ?? null;
                    if (! $orden) {
                        while (in_array($siguiente, $usados, true)) {
                            $siguiente++;
                        }
                        $orden = $siguiente;
                        $usados[] = $orden;
                    }

                    HorarioDetalle::create([
                        'HorarioId' => $horario->HorarioId,
                        'TurnoId' => $fila['TurnoId'],
                        'HorarioDetalleDia' => (int) $dia,
                        'HorarioDetalleOrden' => $orden,
                        'HorarioDetalleEsDescanso' => (bool) ($fila['HorarioDetalleEsDescanso'] ?? false),
                    ]);
                    $total++;
                }
            }

            return ['filas' => $total];
        });
    }
}
