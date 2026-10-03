<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgramacionPeriodoRequest;
use App\Http\Resources\ProgramacionPeriodoResource;
use App\Models\Programacion\ProgramacionPeriodo;
use App\Services\ProgramacionPeriodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramacionPeriodoController extends Controller
{
    private const RELACIONES = [
        'eess:EessId,EessCodigo,EessNombre',
        'tipoPeriodo:TipoPeriodoProgramacionId,TipoPeriodoProgramacionCodigo,TipoPeriodoProgramacionNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
    ];

    // GET /api/programaciones-periodo?buscar=...&eess_id=&tipo_periodo_programacion_id=&anio=&mes=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ProgramacionPeriodo::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ProgramacionPeriodoCodigo', 'like', "%{$buscar}%")
                ->orWhere('ProgramacionPeriodoObservacion', 'like', "%{$buscar}%")))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('tipo_periodo_programacion_id'), fn ($q) => $q->where('TipoPeriodoProgramacionId', $request->integer('tipo_periodo_programacion_id')))
            ->when($request->filled('anio'), fn ($q) => $q->where('ProgramacionPeriodoAnio', $request->integer('anio')))
            ->when($request->filled('mes'), fn ($q) => $q->where('ProgramacionPeriodoMes', $request->integer('mes')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ProgramacionPeriodoEstado', $request->string('estado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('ProgramacionPeriodoFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('ProgramacionPeriodoFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('ProgramacionPeriodoFechaInicio')
            ->orderByDesc('ProgramacionPeriodoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ProgramacionPeriodoResource::collection($registros);
    }

    // POST /api/programaciones-periodo
    public function store(ProgramacionPeriodoRequest $request): JsonResponse
    {
        $programacion = ProgramacionPeriodo::create($request->datos());

        return (new ProgramacionPeriodoResource($programacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/programaciones-periodo/{programacion}
    public function show(ProgramacionPeriodo $programacion)
    {
        return new ProgramacionPeriodoResource($programacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/programaciones-periodo/{programacion}
    public function update(ProgramacionPeriodoRequest $request, ProgramacionPeriodo $programacion)
    {
        $programacion->update($request->datos());

        return new ProgramacionPeriodoResource($programacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/programaciones-periodo/{programacion}
    public function destroy(ProgramacionPeriodo $programacion, ProgramacionPeriodoService $servicio): JsonResponse
    {
        $servicio->anular($programacion);

        return response()->json(['mensaje' => 'Programación anulada.'], 200);
    }

    // POST /api/programaciones-periodo/{programacion}/publicar   (BORRADOR -> PUBLICADA; remitida, ya no se modifica)
    public function publicar(ProgramacionPeriodo $programacion, ProgramacionPeriodoService $servicio): JsonResponse
    {
        return (new ProgramacionPeriodoResource($servicio->publicar($programacion)->load(self::RELACIONES)))->response();
    }

    // POST /api/programaciones-periodo/{programacion}/cerrar   (PUBLICADA -> CERRADA)
    public function cerrar(ProgramacionPeriodo $programacion, ProgramacionPeriodoService $servicio): JsonResponse
    {
        return (new ProgramacionPeriodoResource($servicio->cerrar($programacion)->load(self::RELACIONES)))->response();
    }
}
