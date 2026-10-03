<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * La hora en que opera el negocio (Peru, UTC-5). La aplicacion corre en UTC pero los turnos, las marcaciones y los
 * DEFAULT de SQL Server son de hora local, sin zona. Para compararlos con "ahora" se usa el reloj de pared de Lima
 * (sin zona, igual que lo guardado) y no el instante UTC, que va cinco horas adelantado.
 */
class HoraLocal
{
    public const ZONA = 'America/Lima';

    /** Fecha y hora actuales como reloj de pared de Lima, comparable con las columnas DATE/TIME/DATETIME2 de la base. */
    public static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::ZONA)->format('Y-m-d H:i:s'));
    }

    public static function hoy(): CarbonImmutable
    {
        return self::ahora()->startOfDay();
    }
}
