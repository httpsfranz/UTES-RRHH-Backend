<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InformeGuardiaComunitariaRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\InformeGuardiaComunitariaResource;
use App\Models\Programacion\InformeGuardiaComunitaria;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InformeGuardiaComunitariaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/informes-guardia-comunitaria?buscar=...&vinculo_laboral_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = InformeGuardiaComunitaria::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('InformeGuardiaComunitariaDescripcion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('InformeGuardiaComunitariaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('InformeGuardiaComunitariaFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('InformeGuardiaComunitariaFecha', '<=', $request->date('hasta')))
            ->orderByDesc('InformeGuardiaComunitariaFecha')
            ->orderByDesc('InformeGuardiaComunitariaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return InformeGuardiaComunitariaResource::collection($registros);
    }

    // POST /api/informes-guardia-comunitaria
    public function store(InformeGuardiaComunitariaRequest $request): JsonResponse
    {
        $informe = InformeGuardiaComunitaria::create($request->datos());

        return (new InformeGuardiaComunitariaResource($informe->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/informes-guardia-comunitaria/{informe}
    public function show(InformeGuardiaComunitaria $informe)
    {
        return new InformeGuardiaComunitariaResource($informe->load(self::RELACIONES));
    }

    // PUT|PATCH /api/informes-guardia-comunitaria/{informe}
    public function update(InformeGuardiaComunitariaRequest $request, InformeGuardiaComunitaria $informe)
    {
        $informe->update($request->datos());

        return new InformeGuardiaComunitariaResource($informe->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/informes-guardia-comunitaria/{informe}
    public function destroy(InformeGuardiaComunitaria $informe, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($informe);

        return response()->json(['mensaje' => 'Informe anulado.'], 200);
    }

    // POST /api/informes-guardia-comunitaria/{informe}/aprobar   { UsuarioId, Motivo? }
    public function aprobar(ResolucionRequest $request, InformeGuardiaComunitaria $informe, SolicitudService $servicio): JsonResponse
    {
        $aprobada = $servicio->aprobar($informe, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new InformeGuardiaComunitariaResource($aprobada->load(self::RELACIONES)))->response();
    }

    // POST /api/informes-guardia-comunitaria/{informe}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, InformeGuardiaComunitaria $informe, SolicitudService $servicio): JsonResponse
    {
        $rechazada = $servicio->rechazar($informe, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new InformeGuardiaComunitariaResource($rechazada->load(self::RELACIONES)))->response();
    }
}
