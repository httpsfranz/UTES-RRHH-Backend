<?php

namespace App\Services\ConsultaDni;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** RENIEC a traves de https://api.decolecta.com (GET /v1/reniec/dni?numero=, autenticacion Bearer). */
class DecolectaProveedor implements ProveedorDeDni
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $url = 'https://api.decolecta.com/v1',
        private readonly int $timeout = 5,
    ) {}

    /**
     * Certificados con los que se verifica el HTTPS. PHP en Windows suele venir sin lista de certificados (curl.cainfo y
     * openssl.cafile vacios): en ese caso se usa la que trae composer/ca-bundle, sin tocar el php.ini.
     */
    private static function certificados(): bool|string
    {
        return filled(ini_get('curl.cainfo')) || filled(ini_get('openssl.cafile')) ? true : CaBundle::getBundledCaBundlePath();
    }

    public function estaConfigurado(): bool
    {
        return filled($this->token);
    }

    public function consultar(string $dni): ?array
    {
        try {
            $respuesta = Http::withToken((string) $this->token)->acceptJson()->timeout($this->timeout)
                ->withOptions(['verify' => self::certificados()])
                ->get(rtrim($this->url, '/').'/reniec/dni', ['numero' => $dni]);
        } catch (ConnectionException $e) {
            throw new ConsultaDniNoDisponible('El servicio de consulta de DNI no respondió.', 0, $e);
        }

        if ($respuesta->status() === 404 || ($respuesta->status() === 400 && blank($respuesta->json('first_name')))) {
            return null;
        }
        if (! $respuesta->successful()) {
            throw new ConsultaDniNoDisponible('El servicio de consulta de DNI rechazó la consulta (código '.$respuesta->status().').');
        }

        $datos = $respuesta->json();
        if (blank($datos['first_name'] ?? null)) {
            return null;
        }

        return [
            'nombres' => trim((string) $datos['first_name']),
            'apellido_paterno' => trim((string) ($datos['first_last_name'] ?? '')),
            'apellido_materno' => trim((string) ($datos['second_last_name'] ?? '')),
        ];
    }
}
