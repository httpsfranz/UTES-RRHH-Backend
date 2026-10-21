<?php

namespace App\Console\Commands;

use App\Services\NotificacionService;
use Illuminate\Console\Command;

/** Envia o reintenta los correos de notificaciones pendientes (ver NotificacionService). */
class EnviarCorreosDeNotificaciones extends Command
{
    protected $signature = 'notificaciones:enviar';

    protected $description = 'Envía o reintenta los correos pendientes de las notificaciones';

    public function handle(NotificacionService $servicio): int
    {
        $this->info('Correos procesados: '.$servicio->enviarPendientes());

        return self::SUCCESS;
    }
}
