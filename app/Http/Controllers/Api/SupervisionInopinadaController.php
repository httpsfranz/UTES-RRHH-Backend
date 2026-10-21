<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupervisionInopinadaRequest;
use App\Http\Resources\SupervisionInopinadaResource;
use App\Models\Disciplina\ExpedientePad;
use App\Models\Disciplina\SupervisionInopinada;
use App\Models\Disciplina\TipoFaltaDisciplinaria;
use App\Services\NotificacionService;
use App\Services\SolicitudService;
use App\Support\AlcanceDeEess;
use App\Support\HoraLocal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->when(AlcanceDeEess::restringe($request), fn ($q) => $q->whereIn('EessId', AlcanceDeEess::ids($request)))
            ->when(AlcanceDeEess::restringeVinculos($request), fn ($q) => $q->whereIn('VinculoLaboralId', AlcanceDeEess::vinculos($request)))
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
    public function update(SupervisionInopinadaRequest $request, SupervisionInopinada $supervision, NotificacionService $notificaciones)
    {
        $antes = $supervision->SupervisionInopinadaEstado;
        $supervision->update($request->datos());

        // Un acta con observaciones se comunica a RRHH y al jefe de la microred: puede dar inicio a un expediente PAD.
        if ($antes !== 'OBSERVADO' && $supervision->SupervisionInopinadaEstado === 'OBSERVADO') {
            $notificaciones->emitir('SUPERVISION_OBSERVADA', 'Supervisión inopinada con observaciones',
                'El acta de supervisión del '.$supervision->SupervisionInopinadaFechaHora?->format('d/m/Y H:i').' registró observaciones: '.($supervision->SupervisionInopinadaResultado ?? 'ver acta').'.',
                '/disciplina/gestion?pestana=supervisiones', $supervision->EessId);
        }

        return new SupervisionInopinadaResource($supervision->fresh()->load(self::RELACIONES));
    }

    // POST /api/supervisiones-inopinadas/{supervision}/iniciar-pad   { TipoFaltaDisciplinariaId, ExpedientePadDescripcion? }
    // Del acta OBSERVADA de un servidor nace el expediente PAD: el acta queda como su documento de sustento.
    public function iniciarPad(Request $request, SupervisionInopinada $supervision): JsonResponse
    {
        $datos = $request->validate([
            'TipoFaltaDisciplinariaId' => ['required', 'integer', Rule::exists(TipoFaltaDisciplinaria::class, 'TipoFaltaDisciplinariaId')],
            'ExpedientePadDescripcion' => ['nullable', 'string', 'max:1500'],
        ], [], ['TipoFaltaDisciplinariaId' => 'tipo de falta']);
        abort_unless($supervision->SupervisionInopinadaEstado === 'OBSERVADO', 422, 'Solo una supervisión OBSERVADA da inicio a un expediente PAD.');
        abort_if($supervision->VinculoLaboralId === null, 422, 'La supervisión no identifica a un servidor: no se puede iniciar un PAD.');
        abort_if(ExpedientePad::query()->where('SupervisionInopinadaId', $supervision->SupervisionInopinadaId)->where('ExpedientePadEstado', '<>', 'ANULADO')->exists(), 422, 'Esta supervisión ya dio inicio a un expediente PAD.');

        $expediente = ExpedientePad::create([
            'VinculoLaboralId' => $supervision->VinculoLaboralId,
            'TipoFaltaDisciplinariaId' => $datos['TipoFaltaDisciplinariaId'],
            'DocumentoSustentoId' => $supervision->DocumentoSustentoId,
            'SupervisionInopinadaId' => $supervision->SupervisionInopinadaId,
            'ExpedientePadFechaInicio' => HoraLocal::hoy()->toDateString(),
            'ExpedientePadDescripcion' => $datos['ExpedientePadDescripcion'] ?? 'Originado en la supervisión inopinada del '.$supervision->SupervisionInopinadaFechaHora?->format('d/m/Y').': '.($supervision->SupervisionInopinadaObservacion ?? $supervision->SupervisionInopinadaResultado),
            'ExpedientePadEstado' => 'INICIADO',
        ]);

        return response()->json(['mensaje' => 'Expediente '.$expediente->ExpedientePadNumero.' iniciado.', 'expediente_id' => $expediente->ExpedientePadId, 'numero' => $expediente->ExpedientePadNumero], 201);
    }

    // DELETE /api/supervisiones-inopinadas/{supervision}
    public function destroy(SupervisionInopinada $supervision, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($supervision);

        return response()->json(['mensaje' => 'Supervisión anulada.'], 200);
    }
}
