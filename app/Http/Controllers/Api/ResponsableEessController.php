<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResponsableEessRequest;
use App\Http\Resources\ResponsableEessResource;
use App\Models\Organizacion\ResponsableEess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResponsableEessController extends Controller
{
    private const RELACIONES = [
        'eess:EessId,EessCodigo,EessNombre,MicroredId',
        'vinculoLaboral:VinculoLaboralId,TrabajadorId,EessId,VinculoLaboralCodigo',
        'vinculoLaboral.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipoResponsabilidad:TipoResponsabilidadId,TipoResponsabilidadCodigo,TipoResponsabilidadNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/responsables-eess?buscar=...&eess_id=&vinculo_laboral_id=&tipo_responsabilidad_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = ResponsableEess::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ResponsableEessDocumentoNumero', 'like', "%{$buscar}%")
                ->orWhere('ResponsableEessObservacion', 'like', "%{$buscar}%")
                ->orWhereHas('vinculoLaboral.trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('vinculo_laboral_id'), fn ($q) => $q->where('VinculoLaboralId', $request->integer('vinculo_laboral_id')))
            ->when($request->filled('tipo_responsabilidad_id'), fn ($q) => $q->where('TipoResponsabilidadId', $request->integer('tipo_responsabilidad_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('ResponsableEessEstado', $request->boolean('estado')))
            ->when($request->filled('microred_id'), fn ($q) => $q->whereHas('eess', fn ($e) => $e->where('MicroredId', $request->integer('microred_id'))))
            ->when($request->filled('vigente'), fn ($q) => $request->boolean('vigente')
                ? $q->where('ResponsableEessEstado', 1)->whereDate('ResponsableEessFechaInicio', '<=', now()->toDateString())->where(fn ($s) => $s->whereNull('ResponsableEessFechaFin')->orWhereDate('ResponsableEessFechaFin', '>=', now()->toDateString()))
                : $q->where(fn ($s) => $s->where('ResponsableEessEstado', 0)->orWhereDate('ResponsableEessFechaInicio', '>', now()->toDateString())->orWhereDate('ResponsableEessFechaFin', '<', now()->toDateString())))

            ->orderBy('EessId')
            ->orderByDesc('ResponsableEessFechaInicio')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ResponsableEessResource::collection($registros);
    }

    // POST /api/responsables-eess
    public function store(ResponsableEessRequest $request): JsonResponse
    {
        $responsable = ResponsableEess::create($request->datos());

        return (new ResponsableEessResource($responsable->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/responsables-eess/{responsable}
    public function show(ResponsableEess $responsable)
    {
        return new ResponsableEessResource($responsable->load(self::RELACIONES));
    }

    // PUT|PATCH /api/responsables-eess/{responsable}
    public function update(ResponsableEessRequest $request, ResponsableEess $responsable)
    {
        $responsable->update($request->datos());

        return new ResponsableEessResource($responsable->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/responsables-eess/{responsable}
    public function destroy(ResponsableEess $responsable): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial se conserva.
        $responsable->update(['ResponsableEessEstado' => false]);

        return response()->json(['mensaje' => 'Responsable desactivado.'], 200);
    }
}
