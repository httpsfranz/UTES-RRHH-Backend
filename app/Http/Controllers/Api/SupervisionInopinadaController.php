<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupervisionInopinadaRequest;
use App\Http\Resources\SupervisionInopinadaResource;
use App\Models\Disciplina\SupervisionInopinada;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisionInopinadaController extends Controller
{
    private const RELACIONES = [
        'eess:EessId,EessCodigo,EessNombre',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'usuario:UsuarioId,UsuarioNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/supervisiones-inopinadas?buscar=...&eess_id=&vinculo_laboral_id=&usuario_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = SupervisionInopinada::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('SupervisionInopinadaResultado', 'like', "%{$buscar}%")
                ->orWhere('SupervisionInopinadaObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('SupervisionInopinadaEstado', $request->string('estado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('SupervisionInopinadaFechaHora', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('SupervisionInopinadaFechaHora', '<=', $request->date('hasta')))
            ->orderByDesc('SupervisionInopinadaFechaHora')
            ->orderByDesc('SupervisionInopinadaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return SupervisionInopinadaResource::collection($registros);
    }

    // POST /api/supervisiones-inopinadas
    public function store(SupervisionInopinadaRequest $request): JsonResponse
    {
        $supervision = SupervisionInopinada::create($request->datos());

        return (new SupervisionInopinadaResource($supervision->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/supervisiones-inopinadas/{supervision}
    public function show(SupervisionInopinada $supervision)
    {
        return new SupervisionInopinadaResource($supervision->load(self::RELACIONES));
    }

    // PUT|PATCH /api/supervisiones-inopinadas/{supervision}
    public function update(SupervisionInopinadaRequest $request, SupervisionInopinada $supervision)
    {
        $supervision->update($request->datos());

        return new SupervisionInopinadaResource($supervision->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/supervisiones-inopinadas/{supervision}
    public function destroy(SupervisionInopinada $supervision, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($supervision);

        return response()->json(['mensaje' => 'Supervisión anulada.'], 200);
    }
}
