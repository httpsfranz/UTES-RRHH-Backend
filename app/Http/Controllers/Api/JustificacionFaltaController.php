<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\JustificacionFaltaRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\JustificacionFaltaResource;
use App\Models\Asistencia\JustificacionFalta;
use App\Services\JustificacionFaltaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JustificacionFaltaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'concepto:ConceptoJustificacionId,ConceptoJustificacionCodigo,ConceptoJustificacionNombre,ConceptoJustificacionRequiereDocumento',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
        'usuarioResolucion:UsuarioId,UsuarioNombre',
    ];

    // GET /api/justificaciones-falta?buscar=...&vinculo_laboral_id=&concepto_justificacion_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = JustificacionFalta::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('JustificacionFaltaDocumentoNumero', 'like', "%{$buscar}%")
                ->orWhere('JustificacionFaltaObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('concepto_justificacion_id'), fn ($q) => $q->where('ConceptoJustificacionId', $request->integer('concepto_justificacion_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('JustificacionFaltaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('JustificacionFaltaFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('JustificacionFaltaFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('JustificacionFaltaFechaRegistro')
            ->orderByDesc('JustificacionFaltaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return JustificacionFaltaResource::collection($registros);
    }

    // POST /api/justificaciones-falta
    public function store(JustificacionFaltaRequest $request): JsonResponse
    {
        $justificacion = JustificacionFalta::create($request->datos());

        return (new JustificacionFaltaResource($justificacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/justificaciones-falta/{justificacion}
    public function show(JustificacionFalta $justificacion)
    {
        return new JustificacionFaltaResource($justificacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/justificaciones-falta/{justificacion}
    public function update(JustificacionFaltaRequest $request, JustificacionFalta $justificacion)
    {
        $justificacion->update($request->datos());

        return new JustificacionFaltaResource($justificacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/justificaciones-falta/{justificacion}
    public function destroy(JustificacionFalta $justificacion, JustificacionFaltaService $servicio): JsonResponse
    {
        $servicio->anular($justificacion);

        return response()->json(['mensaje' => 'Justificación anulada.'], 200);
    }

    // POST /api/justificaciones-falta/{justificacion}/aprobar   { UsuarioId, Motivo? (queda como observacion) }
    public function aprobar(ResolucionRequest $request, JustificacionFalta $justificacion, JustificacionFaltaService $servicio): JsonResponse
    {
        $resultado = $servicio->aprobar($justificacion, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new JustificacionFaltaResource($resultado['justificacion']->load(self::RELACIONES)))
            ->additional(['asistencias_actualizadas' => $resultado['asistencias_actualizadas']])
            ->response();
    }

    // POST /api/justificaciones-falta/{justificacion}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, JustificacionFalta $justificacion, JustificacionFaltaService $servicio): JsonResponse
    {
        $rechazada = $servicio->rechazar($justificacion, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new JustificacionFaltaResource($rechazada->load(self::RELACIONES)))->response();
    }
}
