<?php

namespace Tests;

use App\Models\Seguridad\Usuario;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * La API exige sesion: por defecto cada prueba actua como el administrador del sistema (todos los permisos, alcance
     * global) para centrarse en la regla de negocio que prueba. Las pruebas de acceso (NivelAccesoTest) cambian de usuario
     * con actuarComo() o piden la API sin sesion con sinSesion().
     */
    protected function setUp(): void
    {
        parent::setUp();

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
