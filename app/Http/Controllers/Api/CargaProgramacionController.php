<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CargaProgramacionRequest;
use App\Http\Resources\CargaProgramacionResource;
use App\Models\Programacion\CargaProgramacion;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CargaProgramacionController extends Controller
{
    private const RELACIONES = [
        'eess:EessId,EessCodigo,EessNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
        'tipoPeriodo:TipoPeriodoProgramacionId,TipoPeriodoProgramacionCodigo,TipoPeriodoProgramacionNombre',
    ];

    // GET /api/cargas-programacion?buscar=...&eess_id=&tipo_periodo_programacion_id=&programacion_periodo_id=&anio=&mes=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = CargaProgramacion::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CargaProgramacionCodigo', 'like', "%{$buscar}%")
                ->orWhere('CargaProgramacionDocumentoNumero', 'like', "%{$buscar}%")
                ->orWhere('CargaProgramacionMotivo', 'like', "%{$buscar}%")))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('tipo_periodo_programacion_id'), fn ($q) => $q->where('TipoPeriodoProgramacionId', $request->integer('tipo_periodo_programacion_id')))
            ->when($request->filled('programacion_periodo_id'), fn ($q) => $q->where('ProgramacionPeriodoId', $request->integer('programacion_periodo_id')))
            ->when($request->filled('anio'), fn ($q) => $q->where('CargaProgramacionAnio', $request->integer('anio')))
            ->when($request->filled('mes'), fn ($q) => $q->where('CargaProgramacionMes', $request->integer('mes')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CargaProgramacionEstado', $request->string('estado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CargaProgramacionFechaDocumento', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CargaProgramacionFechaDocumento', '<=', $request->date('hasta')))
            ->orderByDesc('CargaProgramacionFechaDocumento')
            ->orderByDesc('CargaProgramacionId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CargaProgramacionResource::collection($registros);
    }

    // POST /api/cargas-programacion
    public function store(CargaProgramacionRequest $request): JsonResponse
    {
        $carga = CargaProgramacion::create($request->datos());

        return (new CargaProgramacionResource($carga->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/cargas-programacion/{carga}
    public function show(CargaProgramacion $carga)
    {
        return new CargaProgramacionResource($carga->load(self::RELACIONES));
    }

    // PUT|PATCH /api/cargas-programacion/{carga}
    public function update(CargaProgramacionRequest $request, CargaProgramacion $carga)
    {
        $carga->update($request->datos());

        return new CargaProgramacionResource($carga->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/cargas-programacion/{carga}
    public function destroy(CargaProgramacion $carga, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($carga);

        return response()->json(['mensaje' => 'Carga de programación anulada.'], 200);
    }
}
