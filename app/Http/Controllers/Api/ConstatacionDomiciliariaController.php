<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConstatacionDomiciliariaRequest;
use App\Http\Resources\ConstatacionDomiciliariaResource;
use App\Models\Solicitudes\ConstatacionDomiciliaria;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConstatacionDomiciliariaController extends Controller
{
    private const RELACIONES = [
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'descansoMedico:DescansoMedicoId,DescansoMedicoNumeroCitt,DescansoMedicoFechaInicio,DescansoMedicoFechaFin',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
        'usuarioRegistro:UsuarioId,UsuarioNombre',
    ];

    // GET /api/constataciones-domiciliarias?buscar=...&vinculo_laboral_id=&descanso_medico_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ConstatacionDomiciliaria::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ConstatacionDomiciliariaDireccion', 'like', "%{$buscar}%")
                ->orWhere('ConstatacionDomiciliariaResultado', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('descanso_medico_id'), fn ($q) => $q->where('DescansoMedicoId', $request->integer('descanso_medico_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ConstatacionDomiciliariaEstado', $request->string('estado')->toString()))
            ->when($request->filled('eess_id'), fn ($q) => $q->whereHas('vinculoLaboral', fn ($v) => $v->where('EessId', $request->integer('eess_id'))))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('ConstatacionDomiciliariaFecha', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('ConstatacionDomiciliariaFecha', '<=', $request->date('hasta')))
            ->orderByDesc('ConstatacionDomiciliariaFecha')
            ->orderByDesc('ConstatacionDomiciliariaId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ConstatacionDomiciliariaResource::collection($registros);
    }

    // POST /api/constataciones-domiciliarias
    public function store(ConstatacionDomiciliariaRequest $request): JsonResponse
    {
        $constatacion = ConstatacionDomiciliaria::create($request->datos());

        return (new ConstatacionDomiciliariaResource($constatacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/constataciones-domiciliarias/{constatacion}
    public function show(ConstatacionDomiciliaria $constatacion)
    {
        return new ConstatacionDomiciliariaResource($constatacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/constataciones-domiciliarias/{constatacion}
    public function update(ConstatacionDomiciliariaRequest $request, ConstatacionDomiciliaria $constatacion)
    {
        $constatacion->update($request->datos());

        return new ConstatacionDomiciliariaResource($constatacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/constataciones-domiciliarias/{constatacion}
    public function destroy(ConstatacionDomiciliaria $constatacion, SolicitudService $servicio): JsonResponse
    {
        $servicio->anular($constatacion);

        return response()->json(['mensaje' => 'Constatación anulada.'], 200);
    }
}
