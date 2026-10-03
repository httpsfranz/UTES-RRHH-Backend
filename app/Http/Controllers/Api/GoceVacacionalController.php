<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoceVacacionalRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\GoceVacacionalResource;
use App\Models\Vacaciones\GoceVacacional;
use App\Services\GoceVacacionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoceVacacionalController extends Controller
{
    private const RELACIONES = [
        'rolVacacional:RolVacacionalId,PeriodoVacacionalId,RolVacacionalFechaProgramada,RolVacacionalFechaFinProgramada,RolVacacionalDias,RolVacacionalEstado',
        'rolVacacional.periodoVacacional:PeriodoVacacionalId,VinculoLaboralId,PeriodoVacacionalAnio',
        'rolVacacional.periodoVacacional.vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'rolVacacional.periodoVacacional.vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/goces-vacacionales?buscar=...&rol_vacacional_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = GoceVacacional::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('rolVacacional.periodoVacacional.vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('rol_vacacional_id'), fn ($q) => $q->where('RolVacacionalId', $request->integer('rol_vacacional_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('GoceVacacionalEstado', $request->string('estado')->toString()))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->whereHas('rolVacacional.periodoVacacional', fn ($p) => $p->where('VinculoLaboralId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('rolVacacional.periodoVacacional.vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('GoceVacacionalFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('GoceVacacionalFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('GoceVacacionalFechaInicio')
            ->orderByDesc('GoceVacacionalId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return GoceVacacionalResource::collection($registros);
    }

    // POST /api/goces-vacacionales
    public function store(GoceVacacionalRequest $request): JsonResponse
    {
        $goce = GoceVacacional::create($request->datos());

        return (new GoceVacacionalResource($goce->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/goces-vacacionales/{goce}
    public function show(GoceVacacional $goce)
    {
        return new GoceVacacionalResource($goce->load(self::RELACIONES));
    }

    // PUT|PATCH /api/goces-vacacionales/{goce}
    public function update(GoceVacacionalRequest $request, GoceVacacional $goce)
    {
        $goce->update($request->datos());

        return new GoceVacacionalResource($goce->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/goces-vacacionales/{goce}
    public function destroy(GoceVacacional $goce, GoceVacacionalService $servicio): JsonResponse
    {
        $servicio->anular($goce);

        return response()->json(['mensaje' => 'Goce anulado.'], 200);
    }

    // POST /api/goces-vacacionales/{goce}/aprobar   { UsuarioId, Motivo? }   (descuenta los días del período vacacional)
    public function aprobar(ResolucionRequest $request, GoceVacacional $goce, GoceVacacionalService $servicio): JsonResponse
    {
        $aprobado = $servicio->aprobar($goce, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new GoceVacacionalResource($aprobado->load(self::RELACIONES)))->response();
    }

    // POST /api/goces-vacacionales/{goce}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, GoceVacacional $goce, GoceVacacionalService $servicio): JsonResponse
    {
        $rechazado = $servicio->rechazar($goce, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new GoceVacacionalResource($rechazado->load(self::RELACIONES)))->response();
    }
}
