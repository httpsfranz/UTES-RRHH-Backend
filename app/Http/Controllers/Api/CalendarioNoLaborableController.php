<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarioNoLaborableRequest;
use App\Http\Resources\CalendarioNoLaborableResource;
use App\Models\Soporte\CalendarioNoLaborable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarioNoLaborableController extends Controller
{
    // GET /api/calendario-no-laborable?buscar=navidad&microred_id=1&tipo=FERIADO&desde=2026-01-01&hasta=2026-12-31
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
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CalendarioNoLaborableFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CalendarioNoLaborableFecha', '<=', $request->date('hasta')))
            ->orderBy('CalendarioNoLaborableFecha')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CalendarioNoLaborableResource::collection($dias);
    }

    // POST /api/calendario-no-laborable
    public function store(CalendarioNoLaborableRequest $request): JsonResponse
    {
        $dia = CalendarioNoLaborable::create($request->validated());

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
        $dia->update($request->validated());

        return new CalendarioNoLaborableResource($dia->fresh()->load('microred'));
    }

    // DELETE /api/calendario-no-laborable/{dia}
    public function destroy(CalendarioNoLaborable $dia): JsonResponse
    {
        // Esta tabla no tiene columna de Estado (no hay baja logica posible).
        // Nada la referencia todavia via FK, asi que se elimina fisicamente;
        // si en el futuro algo la referencia, SQL Server rechazara el DELETE.
        $dia->delete();

        return response()->json(['mensaje' => 'Día no laborable eliminado.'], 200);
    }
}
