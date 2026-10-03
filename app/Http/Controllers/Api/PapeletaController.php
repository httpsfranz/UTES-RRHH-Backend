<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PapeletaRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\PapeletaResource;
use App\Models\Solicitudes\Papeleta;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PapeletaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipo:TipoPapeletaId,TipoPapeletaCodigo,TipoPapeletaNombre,TipoPapeletaRequiereSustento',
        'motivo:MotivoPapeletaId,MotivoPapeletaCodigo,MotivoPapeletaNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
        'usuarioAutorizacion:UsuarioId,UsuarioNombre',
    ];

    // GET /api/papeletas?buscar=...&vinculo_laboral_id=&tipo_papeleta_id=&motivo_papeleta_id=&estado=&es_dia_completo=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = Papeleta::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('PapeletaNumero', 'like', "%{$buscar}%")
                ->orWhere('PapeletaMotivo', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo_papeleta_id'), fn ($q) => $q->where('TipoPapeletaId', $request->integer('tipo_papeleta_id')))
            ->when($request->filled('motivo_papeleta_id'), fn ($q) => $q->where('MotivoPapeletaId', $request->integer('motivo_papeleta_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('PapeletaEstado', $request->string('estado')->toString()))
            ->when($request->filled('es_dia_completo'), fn ($q) => $q->where('PapeletaEsDiaCompleto', $request->boolean('es_dia_completo')))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('PapeletaFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('PapeletaFecha', '<=', $request->date('hasta')))
            ->orderByDesc('PapeletaFecha')
            ->orderByDesc('PapeletaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return PapeletaResource::collection($registros);
    }

    // POST /api/papeletas
    public function store(PapeletaRequest $request): JsonResponse
    {
        $papeleta = Papeleta::create($request->datos());

        return (new PapeletaResource($papeleta->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/papeletas/{papeleta}
    public function show(Papeleta $papeleta)
    {
        return new PapeletaResource($papeleta->load(self::RELACIONES));
    }

    // PUT|PATCH /api/papeletas/{papeleta}
    public function update(PapeletaRequest $request, Papeleta $papeleta)
    {
        $papeleta->update($request->datos());

        return new PapeletaResource($papeleta->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/papeletas/{papeleta}
    public function destroy(Papeleta $papeleta, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($papeleta);

        return response()->json(['mensaje' => 'Papeleta anulada.'], 200);
    }

    // POST /api/papeletas/{papeleta}/aprobar   { UsuarioId, Motivo? }
    public function aprobar(ResolucionRequest $request, Papeleta $papeleta, SolicitudService $servicio): JsonResponse
    {
        $aprobada = $servicio->aprobar($papeleta, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new PapeletaResource($aprobada->load(self::RELACIONES)))->response();
    }

    // POST /api/papeletas/{papeleta}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, Papeleta $papeleta, SolicitudService $servicio): JsonResponse
    {
        $rechazada = $servicio->rechazar($papeleta, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new PapeletaResource($rechazada->load(self::RELACIONES)))->response();
    }
}
