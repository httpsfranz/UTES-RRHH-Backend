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

    // GET /api/trabajadores/plantilla  -> plantilla_trabajadores.xlsx
    public function plantilla(ImportacionTrabajadoresService $servicio): BinaryFileResponse
    {
        return response()->download($servicio->plantilla(), 'plantilla_trabajadores.xlsx')->deleteFileAfterSend();
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

        $archivo = $request->file('archivo');
        $analisis = $servicio->analizar($archivo->getRealPath(), $archivo->getClientOriginalExtension() ?: 'xlsx');
        $resumen = [
            'total' => $analisis['total'],
            'validas' => count($analisis['validas']),
            'filas_con_error' => $analisis['filas_con_error'],
            'errores_total' => count($analisis['errores']),
            'errores' => array_slice($analisis['errores'], 0, self::MAX_ERRORES_EN_RESPUESTA),
            'importados' => 0,
        ];

        if (! $request->boolean('confirmar')) {
            return response()->json($resumen);
        }
        if ($analisis['errores'] !== [] && ! $request->boolean('omitir_errores')) {
            return response()->json(['message' => 'El archivo tiene filas con errores: corrígelas o importa solo las válidas.'] + $resumen, 422);
        }

        $resumen['importados'] = $servicio->importar($analisis['validas']);

        return response()->json($resumen, 201);
    }
}
