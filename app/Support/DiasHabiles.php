<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plazos en dias habiles del RIT (Art. 32, 37, 64, 73). En los establecimientos se labora de lunes a sabado; en la sede
 * administrativa, de lunes a viernes. Los feriados activos del calendario no cuentan.
 */
class DiasHabiles
{
    /** Dias habiles de [$desde, $hasta], ambos incluidos. */
    public static function entre(string $desde, string $hasta, bool $sede = false): int
    {
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->startOfDay();
        if ($fin < $inicio) {
            return 0;
        }
        $feriados = DB::table('Soporte.CalendarioNoLaborable')->where('CalendarioNoLaborableEstado', 1)
            ->whereDate('CalendarioNoLaborableFecha', '>=', $inicio->toDateString())->whereDate('CalendarioNoLaborableFecha', '<=', $fin->toDateString())
            ->pluck('CalendarioNoLaborableFecha')->map(fn ($f) => substr((string) $f, 0, 10))->flip();

        $cuenta = 0;
        for ($dia = $inicio; $dia <= $fin; $dia = $dia->addDay()) {
            if (! $dia->isSunday() && ! ($sede && $dia->isSaturday()) && ! $feriados->has($dia->toDateString())) {
                $cuenta++;
            }
        }

        return $cuenta;
    }

    /** Dias habiles transcurridos despues de $fecha hasta hoy (0 si es hoy o futura). */
    public static function transcurridosDesde(string $fecha, ?CarbonImmutable $hoy = null): int
    {
        $hoy ??= HoraLocal::hoy();

        return $fecha >= $hoy->toDateString() ? 0 : self::entre(CarbonImmutable::parse($fecha)->addDay()->toDateString(), $hoy->toDateString());
    }

    public static function esSede(int $vinculoId): bool
    {
        return DB::table('Personal.VinculoLaboral as v')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')
            ->where('v.VinculoLaboralId', $vinculoId)->where('m.MicroredCodigo', 'MR-SEDE')->exists();
    }
}
