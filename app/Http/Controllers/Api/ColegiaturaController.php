<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ColegiaturaRequest;
use App\Http\Resources\ColegiaturaResource;
use App\Models\Personal\Colegiatura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColegiaturaController extends Controller
{
    private const RELACIONES = [
        'trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'tipo:ColegiaturaTipoId,ColegiaturaTipoCodigo,ColegiaturaTipoNombre',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/colegiaturas?buscar=056789|rojas&trabajador_id=1&colegiatura_tipo_id=1&es_habilitado=1&es_principal=1
    //     &vencida=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();
        $hoy = now()->toDateString();

        $colegiaturas = Colegiatura::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('ColegiaturaNumero', 'like', "%{$buscar}%")
                ->orWhereHas('trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('colegiatura_tipo_id'), fn ($q) => $q->where('ColegiaturaTipoId', $request->integer('colegiatura_tipo_id')))
            ->when($request->filled('es_habilitado'), fn ($q) => $q->where('ColegiaturaEsHabilitado', $request->boolean('es_habilitado')))
            ->when($request->filled('es_principal'), fn ($q) => $q->where('ColegiaturaEsPrincipal', $request->boolean('es_principal')))
            ->when($request->filled('vencida'), fn ($q) => $request->boolean('vencida')
                ? $q->whereDate('ColegiaturaFechaVencimiento', '<', $hoy)
                : $q->where(fn ($s) => $s->whereNull('ColegiaturaFechaVencimiento')->orWhereDate('ColegiaturaFechaVencimiento', '>=', $hoy)))
            ->when($request->filled('estado'), fn ($q) => $q->where('ColegiaturaEstado', $request->boolean('estado')))
            ->orderBy('TrabajadorId')
            ->orderByDesc('ColegiaturaEsPrincipal')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ColegiaturaResource::collection($colegiaturas);
    }

    // POST /api/colegiaturas
    public function store(ColegiaturaRequest $request): JsonResponse
    {
        $colegiatura = Colegiatura::create($request->validated());

        return (new ColegiaturaResource($colegiatura->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/colegiaturas/{colegiatura}
    public function show(Colegiatura $colegiatura)
    {
        return new ColegiaturaResource($colegiatura->load(self::RELACIONES));
    }

    // PUT|PATCH /api/colegiaturas/{colegiatura}
    public function update(ColegiaturaRequest $request, Colegiatura $colegiatura)
    {
        $colegiatura->update($request->validated());

        return new ColegiaturaResource($colegiatura->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/colegiaturas/{colegiatura}
    public function destroy(Colegiatura $colegiatura): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial de habilitacion profesional se conserva.
        $colegiatura->update(['ColegiaturaEstado' => false]);

        return response()->json(['mensaje' => 'Colegiatura desactivada.'], 200);
    }
}
