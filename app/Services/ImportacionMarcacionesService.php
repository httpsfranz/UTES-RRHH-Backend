<?php

namespace App\Services;

use App\Models\Asistencia\Marcacion;
use App\Support\HojaDeCalculo;
use App\Support\PeriodosDeAsistencia;
use Illuminate\Support\Facades\DB;

/**
 * Marcaciones exportadas por un reloj biometrico comercial (Art. 16): un archivo CSV o Excel con DNI, FECHA_HORA y TIPO
 * (ENTRADA o SALIDA). Sin `confirmar` solo informa que se importaria. Las marcas repetidas se omiten y la asistencia de
 * los dias afectados se recalcula al terminar.
 */
class ImportacionMarcacionesService
{
    public function __construct(private readonly CalculoAsistenciaService $calculo) {}

    /** @return array{validas:int,repetidas:int,errores:list<string>,registradas:int} */
    public function importar(string $ruta, string $extension, bool $confirmar): array
    {
        $metodo = (int) DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'ROSTRO')->value('MetodoMarcacionId');
        $resultado = ['validas' => 0, 'repetidas' => 0, 'errores' => [], 'registradas' => 0];
        $afectados = [];
        $linea = 0;
        $encabezado = null;

        foreach (HojaDeCalculo::filas($ruta, $extension) as $fila) {
            $linea++;
            if ($encabezado === null) {
                $encabezado = array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $fila);

                continue;
            }
            $datos = array_combine(array_slice($encabezado, 0, count($fila)), array_slice($fila, 0, count($encabezado)));
            $dni = trim((string) ($datos['DNI'] ?? ''));
            $momento = str_replace('T', ' ', trim((string) ($datos['FECHA_HORA'] ?? '')));
            $tipo = mb_strtoupper(trim((string) ($datos['TIPO'] ?? '')));
            $fecha = substr($momento, 0, 10);

            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $momento) || ! in_array($tipo, ['ENTRADA', 'SALIDA'], true)) {
                $resultado['errores'][] = "Fila {$linea}: fecha u hora o tipo no válidos.";

                continue;
            }
            $vinculo = DB::table('Personal.VinculoLaboral as v')->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
                ->where('t.TrabajadorNumeroDocumento', $dni)->where('v.VinculoLaboralEstado', 1)->whereDate('v.VinculoLaboralFechaInicio', '<=', $fecha)
                ->where(fn ($q) => $q->whereNull('v.VinculoLaboralFechaFin')->orWhereDate('v.VinculoLaboralFechaFin', '>=', $fecha))->value('v.VinculoLaboralId');
            if ($vinculo === null) {
                $resultado['errores'][] = "Fila {$linea}: el DNI {$dni} no tiene vínculo vigente ese día.";

                continue;
            }
            if (PeriodosDeAsistencia::cerradoEn($fecha)) {
                $resultado['errores'][] = "Fila {$linea}: el período de asistencia del {$fecha} está cerrado.";

                continue;
            }
            if (Marcacion::query()->where('VinculoLaboralId', $vinculo)->where('MarcacionFechaHora', $momento)->where('MarcacionTipo', $tipo)->exists()) {
                $resultado['repetidas']++;

                continue;
            }

            $resultado['validas']++;
            if ($confirmar) {
                Marcacion::create(['VinculoLaboralId' => $vinculo, 'MetodoMarcacionId' => $metodo, 'MarcacionFechaHora' => $momento, 'MarcacionTipo' => $tipo,
                    'MarcacionOrigen' => 'IMPORTACION', 'MarcacionEsValida' => true, 'MarcacionObservacion' => 'Importada desde un reloj.']);
                $resultado['registradas']++;
                $afectados[$vinculo][$fecha] = true;
            }
        }

        foreach ($afectados as $vinculo => $fechas) {
            $dias = array_keys($fechas);
            $this->calculo->calcular(min($dias), max($dias), (int) $vinculo);
        }

        return $resultado;
    }
}
