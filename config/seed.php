<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expansion de volumen de los datos de prueba
    |--------------------------------------------------------------------------
    |
    | DatosPruebaSeeder siembra primero los casos puntuales de cada modulo y luego,
    | si esta opcion esta activa, la "expansion de volumen": toda la Red con su
    | personal, programacion, marcaciones, solicitudes y cierres de mes, como si
    | ya estuviera en uso (database/seeders/Concerns/Volumen*.php).
    |
    | Por omision esta ACTIVA: `php artisan db:seed` deja la base lista para recorrer
    | el sistema. PHPUnit la desactiva (phpunit.xml) porque sus pruebas verifican los
    | casos puntuales, y corre en su propia base: ver `php artisan pruebas:preparar`.
    |
    */

    'volumen' => env('SEED_VOLUMEN', true),

    /*
    |--------------------------------------------------------------------------
    | Base de datos de PHPUnit
    |--------------------------------------------------------------------------
    |
    | Las pruebas nunca tocan la base de trabajo (la que lleva el volumen): corren en
    | esta, que `pruebas:preparar` crea y siembra solo con los casos puntuales.
    | El nombre debe coincidir con DB_DATABASE de phpunit.xml.
    |
    */

    'base_de_pruebas' => 'DB_ControlAsistencia_Test',

];
