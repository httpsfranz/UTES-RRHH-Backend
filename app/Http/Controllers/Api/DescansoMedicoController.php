<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DescansoMedicoRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\DescansoMedicoResource;
use App\Models\Solicitudes\DescansoMedico;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DescansoMedicoController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/descansos-medicos?buscar=...&vinculo_laboral_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = DescansoMedico::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('DescansoMedicoNumeroCitt', 'like', "%{$buscar}%")
                ->orWhere('DescansoMedicoDiagnostico', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('DescansoMedicoEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('DescansoMedicoFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('DescansoMedicoFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('DescansoMedicoFechaInicio')
            ->orderByDesc('DescansoMedicoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DescansoMedicoResource::collection($registros);
    }

    // POST /api/descansos-medicos
    public function store(DescansoMedicoRequest $request): JsonResponse
    {
        $descanso = DescansoMedico::create($request->datos());

        return (new DescansoMedicoResource($descanso->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/descansos-medicos/{descanso}
    public function show(DescansoMedico $descanso)
    {
        return new DescansoMedicoResource($descanso->load(self::RELACIONES));
    }

    // PUT|PATCH /api/descansos-medicos/{descanso}
    public function update(DescansoMedicoRequest $request, DescansoMedico $descanso)
    {
        $descanso->update($request->datos());

        return new DescansoMedicoResource($descanso->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/descansos-medicos/{descanso}
    public function destroy(DescansoMedico $descanso, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($descanso);

        return response()->json(['mensaje' => 'Descanso médico anulado.'], 200);
    }

    // POST /api/descansos-medicos/{descanso}/aprobar   { UsuarioId, Motivo? }
    public function aprobar(ResolucionRequest $request, DescansoMedico $descanso, SolicitudService $servicio): JsonResponse
    {
        $aprobada = $servicio->aprobar($descanso, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new DescansoMedicoResource($aprobada->load(self::RELACIONES)))->response();
    }

    // POST /api/descansos-medicos/{descanso}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, DescansoMedico $descanso, SolicitudService $servicio): JsonResponse
    {
        $rechazada = $servicio->rechazar($descanso, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new DescansoMedicoResource($rechazada->load(self::RELACIONES)))->response();
    }
}
