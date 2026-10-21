<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;

/**
 * Lectura de las hojas de calculo que se importan (trabajadores, programacion de turnos, hojas de asistencia): se lee en
 * flujo, fila a fila, para que miles de filas no carguen el archivo completo en memoria, y cada celda se convierte en un
 * texto limpio sin importar si Excel la guardo como numero, fecha u hora.
 */
class HojaDeCalculo
{
    /**
     * Filas de la PRIMERA hoja del archivo (.xlsx o .csv).
     *
     * @return Generator<int,list<mixed>> numero de fila => celdas
     */
    public static function filas(string $ruta, string $extension): Generator
    {
        $lector = strtolower($extension) === 'csv' ? new LectorCsv : new LectorXlsx;
        $lector->open($ruta);
        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $numero => $fila) {
                    yield $numero => $fila->toArray();
                }
                break;
            }
        } finally {
            $lector->close();
        }
    }

    /**
     * Texto de una celda, sin espacios de mas (null si esta vacia). Una fecha se devuelve como AAAA-MM-DD (o con la hora,
     * AAAA-MM-DD HH:MM si la trae), una hora como HH:MM y un numero entero sin decimales.
     */
    public static function texto(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('H:i:s') === '00:00:00' ? $valor->format('Y-m-d') : ($valor->format('Y') < 1901 ? $valor->format('H:i') : $valor->format('Y-m-d H:i'));
        }
        if ($valor instanceof DateInterval) {
            return sprintf('%02d:%02d', $valor->d * 24 + $valor->h, $valor->i);
        }
        if (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }
        $texto = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $texto === '' ? null : $texto;
    }

    /** @param  list<mixed>  $celdas */
    public static function vacia(array $celdas): bool
    {
        foreach ($celdas as $celda) {
            if (self::texto($celda) !== null) {
                return false;
            }
        }

        return true;
    }

    /** Para comparar textos escritos a mano: sin tildes, en minusculas y con un solo espacio. */
    public static function normalizar(string $texto): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($texto))));
    }

    /** Fecha escrita como DD/MM/AAAA o numero de serie de Excel -> AAAA-MM-DD (lo demas se devuelve tal cual, para validarlo). */
    public static function fecha(string $texto): string
    {
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $texto, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_numeric($texto) && (float) $texto > 1000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $texto)->toDateString();
        }

        return $texto;
    }

    /** Numero de documento: Excel quita los ceros de la izquierda de un DNI guardado como numero. */
    public static function dni(mixed $celda): ?string
    {
        $texto = self::texto($celda);

        return $texto !== null && ! is_string($celda) && preg_match('/^\d{1,7}$/', $texto) ? str_pad($texto, 8, '0', STR_PAD_LEFT) : $texto;
    }
}
