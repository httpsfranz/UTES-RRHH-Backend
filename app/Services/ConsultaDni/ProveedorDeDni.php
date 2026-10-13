<?php

namespace App\Services\ConsultaDni;

/**
 * Fuente externa que devuelve los datos de una persona a partir de su DNI. Cada proveedor (Decolecta, otro servicio de
 * pago, un servicio institucional) implementa este contrato y se elige en config/services.php: el resto del sistema no
 * depende de cual sea.
 */
interface ProveedorDeDni
{
    /** true si el proveedor esta configurado (por ejemplo, tiene su token). */
    public function estaConfigurado(): bool;

    /**
     * @return array{nombres:string,apellido_paterno:string,apellido_materno:string}|null null = el DNI no existe
     *
     * @throws ConsultaDniNoDisponible el servicio no respondio o rechazo la consulta (sin token valido, limite, caida)
     */
    public function consultar(string $dni): ?array;
}
