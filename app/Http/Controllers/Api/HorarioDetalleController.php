<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HorarioDetalleRequest;
use App\Http\Requests\HorarioDetalleSincronizarRequest;
use App\Http\Resources\HorarioDetalleResource;
use App\Models\Configuracion\Horario;
use App\Models\Configuracion\HorarioDetalle;
use App\Services\HorarioDetalleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioDetalleController extends Controller
{
    private const RELACIONES = ['horario:HorarioId,HorarioCodigo,HorarioNombre', 'turno:TurnoId,TurnoCodigo,TurnoNombre,TurnoHoraEntrada,TurnoHoraSalida'];

    // GET /api/horarios-detalle?horario_id=1&turno_id=2&dia=3&por_pagina=15
    public function index(Request $request)
    {
        $filas = HorarioDetalle::query()
            ->with(self::RELACIONES)
            ->when($request->filled('horario_id'), fn ($q) => $q->where('HorarioId', $request->integer('horario_id')))
            ->when($request->filled('turno_id'), fn ($q) => $q->where('TurnoId', $request->integer('turno_id')))
            ->when($request->filled('dia'), fn ($q) => $q->where('HorarioDetalleDia', $request->integer('dia')))
            ->orderBy('HorarioId')
            ->orderBy('HorarioDetalleDia')
            ->orderBy('HorarioDetalleOrden')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return HorarioDetalleResource::collection($filas);
    }

    // POST /api/horarios-detalle
    public function store(HorarioDetalleRequest $request): JsonResponse
    {
        $datos = $request->validated();
        // Sin orden explicito, el turno queda despues de los que ya tiene ese dia.
        $datos['HorarioDetalleOrden'] ??= 1 + (int) HorarioDetalle::query()
            ->where('HorarioId', $datos['HorarioId'])
            ->where('HorarioDetalleDia', $datos['HorarioDetalleDia'])
            ->max('HorarioDetalleOrden');

        $fila = HorarioDetalle::create($datos);

        return (new HorarioDetalleResource($fila->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/horarios-detalle/{detalle}
    public function show(HorarioDetalle $detalle)
    {
        return new HorarioDetalleResource($detalle->load(self::RELACIONES));
    }

    // PUT|PATCH /api/horarios-detalle/{detalle}
    public function update(HorarioDetalleRequest $request, HorarioDetalle $detalle)
    {
        $detalle->update($request->validated());

        return new HorarioDetalleResource($detalle->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/horarios-detalle/{detalle}
    public function destroy(HorarioDetalle $detalle): JsonResponse
    {
        // Tabla de detalle sin Estado y sin dependientes: se elimina la fila.
        $detalle->delete();

        return response()->json(['mensaje' => 'Fila del horario eliminada.'], 200);
    }

    // GET /api/horarios/{horario}/detalle : la grilla semanal completa del horario
    public function delHorario(Horario $horario)
    {
        $filas = HorarioDetalle::query()
            ->with(self::RELACIONES)
            ->where('HorarioId', $horario->HorarioId)
            ->orderBy('HorarioDetalleDia')
            ->orderBy('HorarioDetalleOrden')
            ->get();

        return HorarioDetalleResource::collection($filas);
    }

    // PUT /api/horarios/{horario}/detalle : reemplaza la grilla semanal completa
    public function sincronizar(HorarioDetalleSincronizarRequest $request, Horario $horario, HorarioDetalleService $servicio)
    {
        $resultado = $servicio->sincronizar($horario, $request->validated('Detalle'));

        return response()->json(['mensaje' => 'Detalle del horario actualizado.', ...$resultado]);
    }
}
