<?php

namespace App\Providers;

use App\Support\CodigoAutomatico;
use App\Support\RegistroAuditoria;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Codigos y numero de plaza que el usuario no escribe: se generan al guardar si vienen vacios.
        Event::listen('eloquent.saving: *', fn (string $nombre, array $datos) => CodigoAutomatico::completar($datos[0]));

        // Auditoria de lo que los usuarios autenticados crean, modifican o eliminan (Seguridad.Auditoria).
        foreach (['created' => 'INSERT', 'updated' => 'UPDATE', 'deleted' => 'DELETE'] as $evento => $operacion) {
            Event::listen("eloquent.{$evento}: *", fn (string $nombre, array $datos) => RegistroAuditoria::registrar($operacion, $datos[0]));
        }
    }
}
