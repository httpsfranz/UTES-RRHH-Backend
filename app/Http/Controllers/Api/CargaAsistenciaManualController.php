<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CargaAsistenciaManualRequest;
use App\Http\Resources\CargaAsistenciaManualResource;
use App\Models\Asistencia\CargaAsistenciaManual;
use App\Services\CargaAsistenciaManualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CargaAsistenciaManualController extends Controller
{
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
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CargaAsistenciaManualEstado', $request->string('estado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CargaAsistenciaManualFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CargaAsistenciaManualFecha', '<=', $request->date('hasta')))
            ->orderByDesc('CargaAsistenciaManualFecha')
            ->orderByDesc('CargaAsistenciaManualId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CargaAsistenciaManualResource::collection($registros);
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
}
