<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompensacionDevolverRequest;
use App\Http\Requests\CompensacionHorariaRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\CompensacionHorariaResource;
use App\Models\Compensaciones\CompensacionHoraria;
use App\Services\CompensacionHorariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompensacionHorariaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipo:TipoCompensacionId,TipoCompensacionCodigo,TipoCompensacionNombre',
        'autorizadoPor:UsuarioId,UsuarioNombre',
    ];

    // GET /api/compensaciones-horarias?buscar=...&vinculo_laboral_id=&tipo_compensacion_id=&asistencia_diaria_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = CompensacionHoraria::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CompensacionHorariaObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo_compensacion_id'), fn ($q) => $q->where('TipoCompensacionId', $request->integer('tipo_compensacion_id')))
            ->when($request->filled('asistencia_diaria_id'), fn ($q) => $q->where('AsistenciaDiariaId', $request->integer('asistencia_diaria_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CompensacionHorariaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CompensacionHorariaFechaGeneracion', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CompensacionHorariaFechaGeneracion', '<=', $request->date('hasta')))
            ->orderByDesc('CompensacionHorariaFechaGeneracion')
            ->orderByDesc('CompensacionHorariaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CompensacionHorariaResource::collection($registros);
    }

    // POST /api/compensaciones-horarias
    public function store(CompensacionHorariaRequest $request): JsonResponse
    {
        $compensacion = CompensacionHoraria::create($request->datos());

        return (new CompensacionHorariaResource($compensacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/compensaciones-horarias/{compensacion}
    public function show(CompensacionHoraria $compensacion)
    {
        return new CompensacionHorariaResource($compensacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/compensaciones-horarias/{compensacion}
    public function update(CompensacionHorariaRequest $request, CompensacionHoraria $compensacion)
    {
        $compensacion->update($request->datos());

        return new CompensacionHorariaResource($compensacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/compensaciones-horarias/{compensacion}
    public function destroy(CompensacionHoraria $compensacion, CompensacionHorariaService $servicio): JsonResponse
    {
        $servicio->anular($compensacion);

        return response()->json(['mensaje' => 'Compensación anulada.'], 200);
    }

    // POST /api/compensaciones-horarias/{compensacion}/aprobar   { UsuarioId, Motivo? }   (requiere autorizacion previa, RIT Art. 17)
    public function aprobar(ResolucionRequest $request, CompensacionHoraria $compensacion, CompensacionHorariaService $servicio): JsonResponse
    {
        $aprobada = $servicio->aprobar($compensacion, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new CompensacionHorariaResource($aprobada->load(self::RELACIONES)))->response();
    }

    // POST /api/compensaciones-horarias/{compensacion}/devolver   { Horas }   (al completar las horas, queda CONSUMIDO)
    public function devolver(CompensacionDevolverRequest $request, CompensacionHoraria $compensacion, CompensacionHorariaService $servicio): JsonResponse
    {
        $devuelta = $servicio->devolver($compensacion, (float) $request->input('Horas'));

        return (new CompensacionHorariaResource($devuelta->load(self::RELACIONES)))->response();
    }
}
