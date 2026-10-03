<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambioTurnoRequest;
use App\Http\Requests\ResolucionRequest;
use App\Http\Resources\CambioTurnoResource;
use App\Models\Programacion\CambioTurno;
use App\Services\CambioTurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CambioTurnoController extends Controller
{
    private const RELACIONES = [
        'tipo:TipoCambioTurnoId,TipoCambioTurnoCodigo,TipoCambioTurnoNombre,TipoCambioTurnoRequiereReemplazante',
        'turnoProgramado:TurnoProgramadoId,ProgramacionTrabajadorId,TurnoId,TurnoProgramadoFecha,TurnoProgramadoHoraEntrada,TurnoProgramadoHoraSalida,TurnoProgramadoEsGuardia,TurnoProgramadoEstado',
        'turnoProgramado.turno:TurnoId,TurnoCodigo,TurnoNombre,TurnoHoraEntrada,TurnoHoraSalida',
        'turnoProgramado.programacionTrabajador:ProgramacionTrabajadorId,ProgramacionPeriodoId',
        'turnoProgramado.programacionTrabajador.periodo:ProgramacionPeriodoId,EessId,ProgramacionPeriodoCodigo,ProgramacionPeriodoEstado',
        'contraparte:TurnoProgramadoId,ProgramacionTrabajadorId,TurnoId,TurnoProgramadoFecha,TurnoProgramadoHoraEntrada,TurnoProgramadoHoraSalida,TurnoProgramadoEstado',
        'contraparte.turno:TurnoId,TurnoCodigo,TurnoNombre,TurnoHoraEntrada,TurnoHoraSalida',
        'turnoNuevo:TurnoId,TurnoCodigo,TurnoNombre,TurnoHoraEntrada,TurnoHoraSalida',
        'solicitante:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'solicitante.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'reemplazante:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'reemplazante.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
        'usuarioAprobacion:UsuarioId,UsuarioNombre',
    ];

    // GET /api/cambios-turno?buscar=...&tipo_cambio_turno_id=&turno_programado_id=&vinculo_laboral_solicitante_id=&vinculo_laboral_reemplazante_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = CambioTurno::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('CambioTurnoMotivo', 'like', "%{$buscar}%")
                ->orWhere('CambioTurnoObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('solicitante.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('tipo_cambio_turno_id'), fn ($q) => $q->where('TipoCambioTurnoId', $request->integer('tipo_cambio_turno_id')))
            ->when($request->filled('turno_programado_id'), fn ($q) => $q->where('TurnoProgramadoId', $request->integer('turno_programado_id')))
            ->when($request->filled('vinculo_laboral_solicitante_id'), fn ($q) => $q->where('VinculoLaboralSolicitanteId', $request->integer('vinculo_laboral_solicitante_id')))
            ->when($request->filled('vinculo_laboral_reemplazante_id'), fn ($q) => $q->where('VinculoLaboralReemplazanteId', $request->integer('vinculo_laboral_reemplazante_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('CambioTurnoEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('turnoProgramado.programacionTrabajador.periodo', fn ($p) => $p->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where(fn ($s) => $s->where('VinculoLaboralSolicitanteId', $request->integer('vinculo_laboral_id'))->orWhere('VinculoLaboralReemplazanteId', $request->integer('vinculo_laboral_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('CambioTurnoFechaSolicitud', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('CambioTurnoFechaSolicitud', '<=', $request->date('hasta')))
            ->orderByDesc('CambioTurnoFechaSolicitud')
            ->orderByDesc('CambioTurnoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return CambioTurnoResource::collection($registros);
    }

    // POST /api/cambios-turno
    public function store(CambioTurnoRequest $request): JsonResponse
    {
        $cambio = CambioTurno::create($request->datos());

        return (new CambioTurnoResource($cambio->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/cambios-turno/{cambio}
    public function show(CambioTurno $cambio)
    {
        return new CambioTurnoResource($cambio->load(self::RELACIONES));
    }

    // PUT|PATCH /api/cambios-turno/{cambio}
    public function update(CambioTurnoRequest $request, CambioTurno $cambio)
    {
        $cambio->update($request->datos());

        return new CambioTurnoResource($cambio->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/cambios-turno/{cambio}
    public function destroy(CambioTurno $cambio, CambioTurnoService $servicio): JsonResponse
    {
        $servicio->anular($cambio);

        return response()->json(['mensaje' => 'Cambio de turno anulado.'], 200);
    }

    // POST /api/cambios-turno/{cambio}/aprobar   { UsuarioId, Motivo? }   (aplica el cambio a la programación publicada)
    public function aprobar(ResolucionRequest $request, CambioTurno $cambio, CambioTurnoService $servicio): JsonResponse
    {
        $aprobado = $servicio->aprobar($cambio, $request->integer('UsuarioId'), $request->input('Motivo'));

        return (new CambioTurnoResource($aprobado->load(self::RELACIONES)))->response();
    }

    // POST /api/cambios-turno/{cambio}/rechazar   { UsuarioId, Motivo }
    public function rechazar(ResolucionRequest $request, CambioTurno $cambio, CambioTurnoService $servicio): JsonResponse
    {
        $rechazado = $servicio->rechazar($cambio, $request->integer('UsuarioId'), $request->string('Motivo')->toString());

        return (new CambioTurnoResource($rechazado->load(self::RELACIONES)))->response();
    }
}
