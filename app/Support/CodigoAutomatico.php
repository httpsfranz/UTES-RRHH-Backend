<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Codigos que el usuario no tiene que inventar: al guardar un registro cuyo codigo (o numero de plaza) viene vacio, se
 * genera uno con las dos primeras letras del nombre y un correlativo (MI-001, TU-014...). Un codigo escrito a mano se
 * respeta y uno existente nunca se borra al editar.
 *
 * Los codigos con significado tecnico (permisos, parametros, estados de asistencia, tipos de documento y de colegio) son
 * claves que el sistema consulta por su valor: esos se siguen escribiendo a mano.
 */
class CodigoAutomatico
{
    /** @var list<string> */
    private const MANUALES = ['PermisoCodigo', 'ParametroSistemaCodigo', 'EstadoAsistenciaCodigo', 'TipoDocumentoIdentidadCodigo', 'ColegiaturaTipoCodigo'];

    /** Prefijo fijo para los registros que no tienen nombre. */
    private const PREFIJOS = ['VinculoLaboralCodigo' => 'VL', 'ProgramacionPeriodoCodigo' => 'PR', 'CargaProgramacionCodigo' => 'CP', 'VinculoLaboralNumeroPlaza' => 'PL'];

    public static function completar(Model $modelo): void
    {
        foreach ($modelo->getFillable() as $columna) {
            $esCodigo = str_ends_with($columna, 'Codigo') && ! in_array($columna, self::MANUALES, true);
            if (! $esCodigo && $columna !== 'VinculoLaboralNumeroPlaza') {
                continue;
            }
            if (filled($modelo->getAttribute($columna))) {
                continue;
            }
            $original = $modelo->exists ? $modelo->getOriginal($columna) : null;
            if (filled($original)) {
                $modelo->setAttribute($columna, $original);

                continue;
            }
            $modelo->setAttribute($columna, self::generar($modelo, $columna));
        }
    }

    private static function generar(Model $modelo, string $columna): string
    {
        $prefijo = self::PREFIJOS[$columna] ?? self::iniciales((string) $modelo->getAttribute(Str::beforeLast($columna, 'Codigo').'Nombre'));
        $existentes = DB::table($modelo->getTable())->where($columna, 'like', $prefijo.'-%')->pluck($columna);
        $ultimo = $existentes->map(fn ($c) => preg_match('/^'.preg_quote($prefijo, '/').'-(\d+)$/', (string) $c, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return sprintf('%s-%03d', $prefijo, $ultimo + 1);
    }

    private static function iniciales(string $nombre): string
    {
        $letras = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii($nombre)));

        return str_pad(substr($letras, 0, 2), 2, 'X');
    }
}
