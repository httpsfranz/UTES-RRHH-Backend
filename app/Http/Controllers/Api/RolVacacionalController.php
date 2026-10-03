<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolVacacionalReprogramarRequest;
use App\Http\Requests\RolVacacionalRequest;
use App\Http\Resources\RolVacacionalResource;
use App\Models\Vacaciones\RolVacacional;
use App\Services\RolVacacionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolVacacionalController extends Controller
{
    private const RELACIONES = [
        'periodoVacacional:PeriodoVacacionalId,VinculoLaboralId,PeriodoVacacionalAnio,PeriodoVacacionalDiasGanados,PeriodoVacacionalDiasDisponibles,PeriodoVacacionalEstado',
        'periodoVacacional.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'periodoVacacional.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
    ];

    // GET /api/roles-vacacionales?buscar=...&periodo_vacacional_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = RolVacacional::query()
            ->with(self::RELACIONES)
            ->withCount(['goces'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('periodoVacacional.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('periodo_vacacional_id'), fn ($q) => $q->where('PeriodoVacacionalId', $request->integer('periodo_vacacional_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('RolVacacionalEstado', $request->string('estado')->toString()))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('periodoVacacional', fn ($p) => $p->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('periodoVacacional.vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('anio'), fn ($q) => $q->whereHas('periodoVacacional', fn ($p) => $p->where('PeriodoVacacionalAnio', $request->integer('anio'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('RolVacacionalFechaProgramada', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('RolVacacionalFechaProgramada', '<=', $request->date('hasta')))
            ->orderByDesc('RolVacacionalFechaProgramada')
            ->orderByDesc('RolVacacionalId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return RolVacacionalResource::collection($registros);
    }

    // POST /api/roles-vacacionales
    public function store(RolVacacionalRequest $request): JsonResponse
    {
        $rolVacacional = RolVacacional::create($request->datos());

        return (new RolVacacionalResource($rolVacacional->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/roles-vacacionales/{rolVacacional}
    public function show(RolVacacional $rolVacacional)
    {
        return new RolVacacionalResource($rolVacacional->load(self::RELACIONES)->loadCount(['goces']));
    }

    // PUT|PATCH /api/roles-vacacionales/{rolVacacional}
    public function update(RolVacacionalRequest $request, RolVacacional $rolVacacional)
    {
        $rolVacacional->update($request->datos());

        return new RolVacacionalResource($rolVacacional->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/roles-vacacionales/{rolVacacional}
    public function destroy(RolVacacional $rolVacacional, RolVacacionalService $servicio): JsonResponse
    {
        $servicio->anular($rolVacacional);

        return response()->json(['mensaje' => 'Programación vacacional anulada.'], 200);
    }

    // POST /api/roles-vacacionales/{rolVacacional}/reprogramar   { RolVacacionalFechaProgramada }
    // Mueve el goce programado a otra fecha: la programación actual queda REPROGRAMADO y nace una nueva (RIT, Art. 71).
    public function reprogramar(RolVacacionalReprogramarRequest $request, RolVacacional $rolVacacional, RolVacacionalService $servicio): JsonResponse
    {
        $nuevo = $servicio->reprogramar($rolVacacional, $request->string('RolVacacionalFechaProgramada')->toString());

        return (new RolVacacionalResource($nuevo->load(self::RELACIONES)->loadCount('goces')))->response()->setStatusCode(201);
    }
}
