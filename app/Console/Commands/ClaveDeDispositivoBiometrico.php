<?php

namespace App\Console\Commands;

use App\Support\ClaveDeDispositivo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClaveDeDispositivoBiometrico extends Command
{
    protected $signature = 'biometria:clave-dispositivo {codigo? : Codigo del dispositivo (p. ej. DISP-LE-01); sin codigo lista los faciales activos}';

    protected $description = 'Muestra la clave con la que un marcador facial (kiosco) se conecta a la API';

    public function handle(): int
    {
        $faciales = DB::table('Biometria.DispositivoMarcacion as d')
            ->leftJoin('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'd.EessId')
            ->where('d.DispositivoMarcacionEstado', 1)->where('d.DispositivoMarcacionTipo', 'like', '%acial%')
            ->when($this->argument('codigo'), fn ($q, $codigo) => $q->where('d.DispositivoMarcacionCodigo', mb_strtoupper(trim($codigo))))
            ->orderBy('d.DispositivoMarcacionCodigo')
            ->get(['d.DispositivoMarcacionCodigo as codigo', 'd.DispositivoMarcacionNombre as nombre', 'e.EessNombre as eess']);

        if ($faciales->isEmpty()) {
            $this->error('No hay un dispositivo facial activo con ese código (regístralo en UTES > Configuración > Biometría).');

            return self::FAILURE;
        }

        $this->table(['Código', 'Dispositivo', 'Establecimiento', 'Clave'], $faciales->map(fn ($d) => [$d->codigo, $d->nombre, $d->eess ?? 'Toda la Red', ClaveDeDispositivo::para($d->codigo)])->all());
        $this->line('Escribe el código y la clave en la configuración del marcador facial. Si cambia la APP_KEY, las claves cambian.');

        return self::SUCCESS;
    }
}
