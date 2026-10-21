<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\VinculoLaboral;
use App\Services\ImportacionProgramacionService;
use App\Services\ProgramacionMensualService;
use App\Support\AlcanceDeEess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Programacion mensual en grilla (trabajador x dia): ver el mes, asignar los turnos de una celda y copiar el mes anterior.
 * Publicar sigue siendo POST /programaciones-periodo/{id}/publicar.
 */
class ProgramacionMensualController extends Controller
{
    private const MAX_ERRORES_EN_RESPUESTA = 300;

    public function __construct(private readonly ProgramacionMensualService $servicio) {}

    // GET /api/programacion-mensual?eess_id=&anio=&mes=
    public function index(Request $request): JsonResponse
    {
        $datos = $this->mes($request);

        return response()->json($this->servicio->grilla($datos['eess_id'], $datos['anio'], $datos['mes']));
    }

    // PUT /api/programacion-mensual/celda   { eess_id, VinculoLaboralId, Fecha, TurnoIds: [] }   [] = dia libre
    public function celda(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'eess_id' => ['required', 'integer'],
            'VinculoLaboralId' => ['required', 'integer', Rule::exists(VinculoLaboral::class, 'VinculoLaboralId')],
            'Fecha' => ['required', 'date_format:Y-m-d'],
            'TurnoIds' => ['present', 'array', 'max:3'],
            'TurnoIds.*' => ['integer', 'distinct'],
        ], [], ['VinculoLaboralId' => 'trabajador', 'Fecha' => 'fecha', 'TurnoIds' => 'turnos']);
        AlcanceDeEess::exigir((int) $datos['eess_id'], $request->user());

        $this->servicio->asignar((int) $datos['eess_id'], (int) $datos['VinculoLaboralId'], $datos['Fecha'], array_map('intval', $datos['TurnoIds']), $request->user());
        $fecha = explode('-', $datos['Fecha']);

        return response()->json($this->servicio->grilla((int) $datos['eess_id'], (int) $fecha[0], (int) $fecha[1]));
    }

    // POST /api/programacion-mensual/copiar   { eess_id, anio, mes }   llena las celdas vacias con el mes anterior
    public function copiar(Request $request): JsonResponse
    {
        $datos = $this->mes($request);
        $resultado = $this->servicio->copiarMesAnterior($datos['eess_id'], $datos['anio'], $datos['mes'], $request->user());

        return response()->json($resultado + ['grilla' => $this->servicio->grilla($datos['eess_id'], $datos['anio'], $datos['mes'])]);
    }

    // GET /api/programacion-mensual/plantilla?eess_id=&anio=&mes=   la grilla del mes en Excel, llena con lo programado
    public function plantilla(Request $request, ImportacionProgramacionService $importacion): BinaryFileResponse
    {
        $datos = $this->mes($request);
        $nombre = sprintf('programacion_%s_%04d-%02d.xlsx', EstablecimientoSalud::query()->whereKey($datos['eess_id'])->value('EessCodigo'), $datos['anio'], $datos['mes']);

        return response()->download($importacion->plantilla($datos['eess_id'], $datos['anio'], $datos['mes']), $nombre)->deleteFileAfterSend();
    }

    // POST /api/programacion-mensual/importar  (multipart: eess_id, anio, mes, archivo; confirmar=1 guarda; omitir_errores=1
    // guarda solo las celdas validas). Sin `confirmar` solo valida: ensaya los cambios y los deshace.
    public function importar(Request $request, ImportacionProgramacionService $importacion): JsonResponse
    {
        $datos = $this->mes($request);
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
            'confirmar' => ['sometimes', 'boolean'],
            'omitir_errores' => ['sometimes', 'boolean'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel con la programación.',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx) o un CSV.',
            'archivo.max' => 'El archivo supera los 5 MB.',
        ]);
        set_time_limit(300); // cada celda que cambia pasa por las reglas de la grilla

        $archivo = $request->file('archivo');
        $confirmar = $request->boolean('confirmar');
        $omitir = $request->boolean('omitir_errores');
        $resultado = $importacion->importar(
            $datos['eess_id'], $datos['anio'], $datos['mes'], $archivo->getRealPath(), $archivo->getClientOriginalExtension() ?: 'xlsx',
            $request->user(), $confirmar, $omitir,
        );
        $resultado['errores_total'] = count($resultado['errores']);
        $resultado['errores'] = array_slice($resultado['errores'], 0, self::MAX_ERRORES_EN_RESPUESTA);

        if (! $confirmar) {
            return response()->json($resultado);
        }
        if ($resultado['errores_total'] > 0 && ! $omitir) {
            return response()->json(['message' => 'El archivo tiene días con errores: corrígelos o importa solo los válidos.'] + $resultado, 422);
        }

        return response()->json($resultado + ['grilla' => $this->servicio->grilla($datos['eess_id'], $datos['anio'], $datos['mes'])], 201);
    }

    /** @return array{eess_id:int,anio:int,mes:int} */
    private function mes(Request $request): array
    {
        $datos = $request->validate([
            'eess_id' => ['required', 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')],
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
        ], [], ['eess_id' => 'establecimiento']);
        AlcanceDeEess::exigir((int) $datos['eess_id'], $request->user());

        return array_map('intval', $datos);
    }
}
