<?php

namespace App\Services\ConsultaDni;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de DNI para autocompletar el alta de un trabajador. Nunca lanza: si el proveedor no esta configurado o falla,
 * responde `disponible = false` y el formulario se llena a mano. Los resultados se guardan unas horas para no repetir (ni
 * cobrar) la misma consulta.
 */
class ConsultaDniService
{
    private const VIGENCIA_SEGUNDOS = 6 * 3600;

    public function __construct(private readonly ?ProveedorDeDni $proveedor) {}

    /** @return array{disponible:bool,encontrado:bool,existe_en_sistema:bool,datos:array<string,string>|null,mensaje:string|null} */
    public function consultar(string $dni): array
    {
        $existe = DB::table('Personal.Trabajador as t')
            ->join('Personal.TipoDocumentoIdentidad as d', 'd.TipoDocumentoIdentidadId', '=', 't.TipoDocumentoIdentidadId')
            ->where('d.TipoDocumentoIdentidadCodigo', 'DNI')->where('t.TrabajadorNumeroDocumento', $dni)->exists();

        $base = ['disponible' => true, 'encontrado' => false, 'existe_en_sistema' => $existe, 'datos' => null, 'mensaje' => null];

        if ($this->proveedor === null || ! $this->proveedor->estaConfigurado()) {
            return ['disponible' => false, 'mensaje' => 'La consulta automática de DNI no está configurada.'] + $base;
        }

        try {
            $datos = Cache::remember("consulta-dni:{$dni}", self::VIGENCIA_SEGUNDOS, fn () => $this->proveedor->consultar($dni) ?? false);
        } catch (ConsultaDniNoDisponible $e) {
            Log::warning('Consulta de DNI no disponible: '.$e->getMessage());

            return ['disponible' => false, 'mensaje' => 'No se pudo consultar el DNI en este momento. Escribe los datos manualmente.'] + $base;
        }

        if ($datos === false) {
            return ['mensaje' => 'No se encontró ese DNI.'] + $base;
        }

        return ['encontrado' => true, 'datos' => $datos] + $base;
    }
}
