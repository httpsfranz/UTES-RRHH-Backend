<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AjusteMarcacionRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\AjusteMarcacionResource;
use App\Models\Asistencia\AjusteMarcacion;
use App\Services\AjusteMarcacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AjusteMarcacionController extends Controller
{
    private const RELACIONES = [
        'marcacion:MarcacionId,VinculoLaboralId,MarcacionFechaHora,MarcacionTipo,MarcacionEsValida',
        'marcacion.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'marcacion.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'usuario:UsuarioId,UsuarioNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/ajustes-marcacion?buscar=...&marcacion_id=&usuario_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = AjusteMarcacion::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('AjusteMarcacionMotivo', 'like', "%{$buscar}%")
                ->orWhereHas('marcacion.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('marcacion_id'), fn ($q) => $q->where('MarcacionId', $request->integer('marcacion_id')))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('AjusteMarcacionEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('marcacion.vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('marcacion', fn ($m) => $m->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('AjusteMarcacionFechaHora', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('AjusteMarcacionFechaHora', '<=', $request->date('hasta')))
            ->orderByDesc('AjusteMarcacionFechaHora')
            ->orderByDesc('AjusteMarcacionId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return AjusteMarcacionResource::collection($registros);
    }

    // POST /api/ajustes-marcacion
    public function store(AjusteMarcacionRequest $request): JsonResponse
    {
        $ajuste = AjusteMarcacion::create($request->datos());

        return (new AjusteMarcacionResource($ajuste->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/ajustes-marcacion/{ajuste}
    public function show(AjusteMarcacion $ajuste)
    {
        return new AjusteMarcacionResource($ajuste->load(self::RELACIONES));
    }

    // PUT|PATCH /api/ajustes-marcacion/{ajuste}
    public function update(AjusteMarcacionRequest $request, AjusteMarcacion $ajuste)
    {
        $ajuste->update($request->datos());

        return new AjusteMarcacionResource($ajuste->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/ajustes-marcacion/{ajuste}
    public function destroy(AjusteMarcacion $ajuste, AjusteMarcacionService $servicio): JsonResponse
    {
        $servicio->anular($ajuste);

        return response()->json(['mensaje' => 'Ajuste anulado.'], 200);
    }

    // POST /api/ajustes-marcacion/{ajuste}/aprobar   { UsuarioId, Motivo? }   (corrige la marcación)
    public function aprobar(ResolucionRequest $request, AjusteMarcacion $ajuste, AjusteMarcacionService $servicio): JsonResponse
    {
        $aprobado = $servicio->aprobar($ajuste, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new AjusteMarcacionResource($aprobado->load(self::RELACIONES)))->response();
    }

    // POST /api/ajustes-marcacion/{ajuste}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, AjusteMarcacion $ajuste, AjusteMarcacionService $servicio): JsonResponse
    {
        $rechazado = $servicio->rechazar($ajuste, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new AjusteMarcacionResource($rechazado->load(self::RELACIONES)))->response();
    }
}
