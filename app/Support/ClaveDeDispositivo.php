<?php

namespace App\Support;

/**
 * Clave con la que un marcador facial (kiosco) se identifica ante la API, sin cuenta de usuario ni sesion (un kiosco
 * no puede depender de una sesion que vence por inactividad).
 *
 * No se guarda en la base: se deriva del codigo del dispositivo y de la APP_KEY (HMAC), asi que no hace falta una
 * columna nueva. Se obtiene con `php artisan biometria:clave-dispositivo DISP-LE-01` y se escribe una sola vez en la
 * configuracion del kiosco. Para revocarla basta con desactivar el dispositivo en Biometria.DispositivoMarcacion.
 */
class ClaveDeDispositivo
{
    public static function para(string $codigo): string
    {
        return substr(hash_hmac('sha256', 'marcador-facial|'.mb_strtoupper(trim($codigo)), (string) config('app.key')), 0, 32);
    }

    public static function valida(string $codigo, string $clave): bool
    {
        return $codigo !== '' && hash_equals(self::para($codigo), $clave);
    }
}
