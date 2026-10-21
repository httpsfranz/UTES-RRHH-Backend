<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CargaAsistenciaManualRequest;
use App\Http\Resources\CargaAsistenciaManualResource;
use App\Models\Asistencia\CargaAsistenciaManual;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Soporte\DocumentoSustento;
use App\Services\CargaAsistenciaManualService;
use App\Services\ImportacionHojaAsistenciaService;
use App\Support\AlcanceDeEess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CargaAsistenciaManualController extends Controller
{
    private const MAX_ERRORES_EN_RESPUESTA = 300;

    private const RELACIONES = [
        'usuario:UsuarioId,UsuarioNombre',
        'eess:EessId,EessCodigo,EessNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/cargas-asistencia-manual?buscar=...&eess_id=&usuario_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = CargaAsistenciaManual::query()
            ->with(self::RELACIONES)
            ->withCount(['marcaciones'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CargaAsistenciaManualNombreArchivo', 'like', "%{$buscar}%")
                ->orWhere('CargaAsistenciaManualObservacion', 'like', "%{$buscar}%")))
            ->when(AlcanceDeEess::restringe($request), fn ($q) => $q->whereIn('EessId', AlcanceDeEess::ids($request)))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CargaAsistenciaManualEstado', $request->string('estado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CargaAsistenciaManualFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CargaAsistenciaManualFecha', '<=', $request->date('hasta')))
            ->orderByDesc('CargaAsistenciaManualFecha')
            ->orderByDesc('CargaAsistenciaManualId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CargaAsistenciaManualResource::collection($registros);
    }

    // GET /api/cargas-asistencia-manual/plantilla?eess_id=&anio=&mes=   hoja de asistencia del mes en Excel
    public function plantilla(Request $request, ImportacionHojaAsistenciaService $importacion): BinaryFileResponse
    {
        $datos = $this->mes($request);
        if ($motivo = $importacion->motivoSinHojas($datos['eess_id'])) {
            throw new DomainException($motivo);
        }
        $nombre = sprintf('hoja_asistencia_%s_%04d-%02d.xlsx', EstablecimientoSalud::query()->whereKey($datos['eess_id'])->value('EessCodigo'), $datos['anio'], $datos['mes']);

        return response()->download($importacion->plantilla($datos['eess_id'], $datos['anio'], $datos['mes']), $nombre)->deleteFileAfterSend();
    }

    // POST /api/cargas-asistencia-manual/importar  (multipart: eess_id, anio, mes, archivo, DocumentoSustentoId = la hoja
    // firmada escaneada; confirmar=1 guarda; omitir_errores=1 guarda solo las filas validas). Sin `confirmar` solo valida.
    public function importar(Request $request, ImportacionHojaAsistenciaService $importacion): JsonResponse
    {
        $datos = $this->mes($request) + $request->validate([
            'archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
            'DocumentoSustentoId' => ['required', 'integer', Rule::exists(DocumentoSustento::class, 'DocumentoSustentoId')],
            'confirmar' => ['sometimes', 'boolean'],
            'omitir_errores' => ['sometimes', 'boolean'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel con la hoja de asistencia.',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx) o un CSV.',
            'archivo.max' => 'El archivo supera los 5 MB.',
            'DocumentoSustentoId.required' => 'Adjunta la hoja de asistencia firmada (escaneada) como sustento.',
        ], ['DocumentoSustentoId' => 'documento de sustento']);
        set_time_limit(300);

        $archivo = $request->file('archivo');
        $confirmar = $request->boolean('confirmar');
        $omitir = $request->boolean('omitir_errores');
        $resultado = $importacion->importar(
            $datos['eess_id'], $datos['anio'], $datos['mes'], $archivo->getRealPath(), $archivo->getClientOriginalExtension() ?: 'xlsx',
            $archivo->getClientOriginalName(), (int) $datos['DocumentoSustentoId'], $request->user(), $confirmar, $omitir,
        );
        $resultado['errores_total'] = count($resultado['errores']);
        $resultado['errores'] = array_slice($resultado['errores'], 0, self::MAX_ERRORES_EN_RESPUESTA);

        if (! $confirmar) {
            return response()->json($resultado);
        }
        if ($resultado['errores_total'] > 0 && ! $omitir) {
            return response()->json(['message' => 'La hoja tiene filas con errores: corrígelas o importa solo las válidas.'] + $resultado, 422);
        }
        if ($resultado['registradas'] === 0) {
            return response()->json(['message' => 'La hoja no tiene marcas nuevas para registrar.'] + $resultado, 422);
        }

        return response()->json($resultado, 201);
    }

    // POST /api/cargas-asistencia-manual
    public function store(CargaAsistenciaManualRequest $request): JsonResponse
    {
        $carga = CargaAsistenciaManual::create($request->datos());

        return (new CargaAsistenciaManualResource($carga->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/cargas-asistencia-manual/{carga}
    public function show(CargaAsistenciaManual $carga)
    {
        return new CargaAsistenciaManualResource($carga->load(self::RELACIONES)->loadCount(['marcaciones']));
    }

    // PUT|PATCH /api/cargas-asistencia-manual/{carga}
    public function update(CargaAsistenciaManualRequest $request, CargaAsistenciaManual $carga)
    {
        $carga->update($request->datos());

        return new CargaAsistenciaManualResource($carga->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/cargas-asistencia-manual/{carga}
    public function destroy(CargaAsistenciaManual $carga, CargaAsistenciaManualService $servicio): JsonResponse
    {
        $servicio->anular($carga);

        return response()->json(['mensaje' => 'Carga anulada.'], 200);
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
