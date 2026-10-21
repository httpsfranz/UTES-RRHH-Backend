<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarioNoLaborableRequest;
use App\Http\Resources\CalendarioNoLaborableResource;
use App\Models\Soporte\CalendarioNoLaborable;
use App\Services\CalendarioNoLaborableService;
use App\Support\PeriodosDeAsistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarioNoLaborableController extends Controller
{
    public function __construct(private CalendarioNoLaborableService $servicio) {}

    // GET /api/calendario-no-laborable?buscar=navidad&microred_id=1&tipo=FERIADO&anio=2026&mes=12&estado=1&desde=&hasta=
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $dias = CalendarioNoLaborable::query()
            ->with('microred:MicroredId,MicroredNombre')
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CalendarioNoLaborableDescripcion', 'like', "%{$buscar}%")
                ->orWhere('CalendarioNoLaborableNormaSustento', 'like', "%{$buscar}%")
                ->orWhereRaw('CONVERT(varchar(10), CalendarioNoLaborableFecha, 23) like ?', ["%{$buscar}%"])))
            ->when($request->filled('microred_id'), fn ($q) => $q->where('MicroredId', $request->integer('microred_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('CalendarioNoLaborableTipo', $request->string('tipo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('CalendarioNoLaborableEstado', $request->boolean('estado')))
            // Consulta por año y por mes.
            ->when($request->filled('anio'), fn ($q) => $q->whereYear('CalendarioNoLaborableFecha', $request->integer('anio')))
            ->when($request->filled('mes'), fn ($q) => $q->whereMonth('CalendarioNoLaborableFecha', $request->integer('mes')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CalendarioNoLaborableFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CalendarioNoLaborableFecha', '<=', $request->date('hasta')))
            ->orderBy('CalendarioNoLaborableFecha')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CalendarioNoLaborableResource::collection($dias);
    }

    // POST /api/calendario-no-laborable   (la norma de sustento se completa sola si no se escribe)
    public function store(CalendarioNoLaborableRequest $request): JsonResponse
    {
        $dia = CalendarioNoLaborable::create($request->datos());
        $this->servicio->aplicar($dia->fresh());

        return (new CalendarioNoLaborableResource($dia->fresh()->load('microred')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/calendario-no-laborable/{dia}
    public function show(CalendarioNoLaborable $dia)
    {
        return new CalendarioNoLaborableResource($dia->load('microred'));
    }

    // PUT|PATCH /api/calendario-no-laborable/{dia}
    public function update(CalendarioNoLaborableRequest $request, CalendarioNoLaborable $dia)
    {
        $anterior = $dia->CalendarioNoLaborableFecha?->toDateString();
        $dia->update($request->datos());
        $this->servicio->aplicar($dia->fresh(), $anterior);

        return new CalendarioNoLaborableResource($dia->fresh()->load('microred'));
    }

    // DELETE /api/calendario-no-laborable/{dia}   baja logica: queda sin efecto pero se conserva con su norma
    public function destroy(CalendarioNoLaborable $dia): JsonResponse
    {
        abort_if(PeriodosDeAsistencia::cerradoEn($dia->CalendarioNoLaborableFecha?->toDateString()), 422, 'El período de asistencia de ese día está cerrado: su calendario ya no se modifica.');
        $dia->update(['CalendarioNoLaborableEstado' => false]);
        $this->servicio->aplicar($dia->fresh());

        return response()->json(['mensaje' => 'Día no laborable desactivado.'], 200);
    }
}
