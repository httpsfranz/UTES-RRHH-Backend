<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LicenciaRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\LicenciaResource;
use App\Models\Solicitudes\Licencia;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenciaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipo:TipoLicenciaId,TipoLicenciaCodigo,TipoLicenciaNombre,TipoLicenciaConGoce,TipoLicenciaMaximoDias',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
    ];

    // GET /api/licencias?buscar=...&vinculo_laboral_id=&tipo_licencia_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = Licencia::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('LicenciaNumeroResolucion', 'like', "%{$buscar}%")
                ->orWhere('LicenciaMotivo', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo_licencia_id'), fn ($q) => $q->where('TipoLicenciaId', $request->integer('tipo_licencia_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('LicenciaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('LicenciaFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('LicenciaFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('LicenciaFechaInicio')
            ->orderByDesc('LicenciaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return LicenciaResource::collection($registros);
    }

    // POST /api/licencias
    public function store(LicenciaRequest $request): JsonResponse
    {
        $licencia = Licencia::create($request->datos());

        return (new LicenciaResource($licencia->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/licencias/{licencia}
    public function show(Licencia $licencia)
    {
        return new LicenciaResource($licencia->load(self::RELACIONES));
    }

    // PUT|PATCH /api/licencias/{licencia}
    public function update(LicenciaRequest $request, Licencia $licencia)
    {
        $licencia->update($request->datos());

        return new LicenciaResource($licencia->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/licencias/{licencia}
    public function destroy(Licencia $licencia, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($licencia);

        return response()->json(['mensaje' => 'Licencia anulada.'], 200);
    }

    // POST /api/licencias/{licencia}/aprobar   { UsuarioId, Motivo? }
    public function aprobar(ResolucionRequest $request, Licencia $licencia, SolicitudService $servicio): JsonResponse
    {
        $aprobada = $servicio->aprobar($licencia, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new LicenciaResource($aprobada->load(self::RELACIONES)))->response();
    }

    // POST /api/licencias/{licencia}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, Licencia $licencia, SolicitudService $servicio): JsonResponse
    {
        $rechazada = $servicio->rechazar($licencia, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new LicenciaResource($rechazada->load(self::RELACIONES)))->response();
    }
}
