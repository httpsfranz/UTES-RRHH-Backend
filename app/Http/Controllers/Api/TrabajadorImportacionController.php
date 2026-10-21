<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImportacionTrabajadoresService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrabajadorImportacionController extends Controller
{
    private const MAX_ERRORES_EN_RESPUESTA = 300;

    private const SEGUNDOS_MAXIMOS = 600;

    // GET /api/trabajadores/plantilla  -> plantilla_trabajadores.xlsx
    public function plantilla(Request $request, ImportacionTrabajadoresService $servicio): BinaryFileResponse
    {
        return response()->download($servicio->plantilla($request->user()), 'plantilla_trabajadores.xlsx')->deleteFileAfterSend();
    }

    // POST /api/trabajadores/importar  (multipart: archivo; confirmar=1 guarda; omitir_errores=1 guarda solo las filas validas)
    // Sin `confirmar` solo valida y devuelve el resumen: nada se guarda.
    public function importar(Request $request, ImportacionTrabajadoresService $servicio): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
            'confirmar' => ['sometimes', 'boolean'],
            'omitir_errores' => ['sometimes', 'boolean'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel con los trabajadores.',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx) o un CSV.',
            'archivo.max' => 'El archivo supera los 10 MB: divídelo en varios.',
        ]);

        // Cada vinculo pasa por las reglas del formulario (unos 15 ms por fila): miles de filas superan el limite normal.
        set_time_limit(self::SEGUNDOS_MAXIMOS);
        $archivo = $request->file('archivo');
        $confirmar = $request->boolean('confirmar');
        $omitir = $request->boolean('omitir_errores');
        // Al confirmar no se ensaya: importar() valida cada vinculo al guardarlo y devuelve los que no pasan.
        $analisis = $servicio->analizar($archivo->getRealPath(), $archivo->getClientOriginalExtension() ?: 'xlsx', $request->user(), ! $confirmar);

        if (! $confirmar) {
            return response()->json($this->resumen($analisis['total'], $analisis['validas'], $analisis['errores'], $analisis['filas_con_error']));
        }
        if ($analisis['errores'] !== [] && ! $omitir) {
            return response()->json(['message' => 'El archivo tiene filas con errores: corrígelas o importa solo las válidas.']
                + $this->resumen($analisis['total'], $analisis['validas'], $analisis['errores'], $analisis['filas_con_error']), 422);
        }

        $guardado = $servicio->importar($analisis['validas'], $request->user(), $omitir);
        $errores = [...$analisis['errores'], ...$guardado['errores']];
        usort($errores, fn ($a, $b) => $a['fila'] <=> $b['fila']);
        $resumen = $this->resumen(
            $analisis['total'], $servicio->sinRechazadas($analisis['validas'], $guardado['errores']), $errores,
            $analisis['filas_con_error'] + count(array_unique(array_column($guardado['errores'], 'fila'))),
        );
        if ($guardado['errores'] !== [] && ! $omitir) {
            return response()->json(['message' => 'Algunos vínculos u horarios no cumplen las reglas: corrígelos o importa solo las filas válidas.'] + $resumen, 422);
        }

        return response()->json(['importados' => $guardado['trabajadores'], 'vinculos' => $guardado['vinculos'], 'horarios' => $guardado['horarios']] + $resumen, 201);
    }

    /**
     * @param  list<array<string,mixed>>  $validas
     * @param  list<array{fila:int,campo:string,mensaje:string}>  $errores
     * @return array<string,mixed>
     */
    private function resumen(int $total, array $validas, array $errores, int $filasConError): array
    {
        return [
            'total' => $total,
            'validas' => count($validas),
            'filas_con_error' => $filasConError,
            'errores_total' => count($errores),
            'errores' => array_slice($errores, 0, self::MAX_ERRORES_EN_RESPUESTA),
            // De las filas validas, cuantas registran tambien su vinculo laboral y su horario.
            'con_vinculo' => count(array_filter($validas, fn ($f) => $f['vinculo'] !== null)),
            'con_horario' => count(array_filter($validas, fn ($f) => $f['horario_id'] !== null)),
            'importados' => 0,
            'vinculos' => 0,
            'horarios' => 0,
        ];
    }
}
