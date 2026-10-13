<?php

namespace App\Providers;

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
        // Auditoria de lo que los usuarios autenticados crean, modifican o eliminan (Seguridad.Auditoria).
        foreach (['created' => 'INSERT', 'updated' => 'UPDATE', 'deleted' => 'DELETE'] as $evento => $operacion) {
            Event::listen("eloquent.{$evento}: *", fn (string $nombre, array $datos) => RegistroAuditoria::registrar($operacion, $datos[0]));
        }
    }
}
