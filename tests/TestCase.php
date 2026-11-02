<?php

namespace Tests;

use App\Models\Seguridad\Usuario;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Instante fijo de las pruebas, en UTC (10:00 en Lima). */
    protected const AHORA = '2026-10-20 15:00:00';

    /**
     * La API exige sesion: por defecto cada prueba actua como el administrador del sistema (todos los permisos, alcance
     * global) para centrarse en la regla de negocio que prueba. Las pruebas de acceso (NivelAccesoTest) cambian de usuario
     * con actuarComo() o piden la API sin sesion con sinSesion().
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Los datos de prueba son de setiembre y octubre de 2026: el reloj se fija para que las reglas con plazos no dependan del dia en que se corre la suite.
        Carbon::setTestNow(self::AHORA);
        CarbonImmutable::setTestNow(self::AHORA);

        $this->actuarComo('pgutierrez');
    }

    protected function actuarComo(string $nombreDeUsuario): Usuario
    {
        $usuario = Usuario::query()->where('UsuarioNombre', $nombreDeUsuario)->firstOrFail();
        Sanctum::actingAs($usuario, ['*']);

        return $usuario;
    }

    protected function sinSesion(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
