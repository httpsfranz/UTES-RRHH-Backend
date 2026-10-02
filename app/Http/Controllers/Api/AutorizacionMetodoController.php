<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutorizacionMetodoRequest;
use App\Http\Resources\AutorizacionMetodoResource;
use App\Models\Biometria\AutorizacionMetodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutorizacionMetodoController extends Controller
{
    private const RELACIONES = [
        'trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'metodo:MetodoMarcacionId,MetodoMarcacionCodigo,MetodoMarcacionNombre',
    ];

    // GET /api/autorizaciones-metodo?buscar=rojas&trabajador_id=1&metodo_marcacion_id=2&vigente=1&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $autorizaciones = AutorizacionMetodo::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('trabajador', fn ($t) => $t
                    ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                    ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%"))
                ->orWhereHas('metodo', fn ($m) => $m->where('MetodoMarcacionNombre', 'like', "%{$buscar}%"))))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('metodo_marcacion_id'), fn ($q) => $q->where('MetodoMarcacionId', $request->integer('metodo_marcacion_id')))
            ->when($request->filled('vigente'), fn ($q) => $request->boolean('vigente')
                ? $q->vigentes()
                : $q->where(fn ($s) => $s->where('AutorizacionMetodoEstado', 0)
                    ->orWhereDate('AutorizacionMetodoFechaFin', '<', now()->toDateString())
                    ->orWhereDate('AutorizacionMetodoFechaInicio', '>', now()->toDateString())))
            ->when($request->filled('estado'), fn ($q) => $q->where('AutorizacionMetodoEstado', $request->boolean('estado')))
            ->orderByDesc('AutorizacionMetodoFechaInicio')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return AutorizacionMetodoResource::collection($autorizaciones);
    }

    // POST /api/autorizaciones-metodo
    public function store(AutorizacionMetodoRequest $request): JsonResponse
    {
        $autorizacion = AutorizacionMetodo::create($request->validated());

        return (new AutorizacionMetodoResource($autorizacion->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/autorizaciones-metodo/{autorizacion}
    public function show(AutorizacionMetodo $autorizacion)
    {
        return new AutorizacionMetodoResource($autorizacion->load(self::RELACIONES));
    }

    // PUT|PATCH /api/autorizaciones-metodo/{autorizacion}
    public function update(AutorizacionMetodoRequest $request, AutorizacionMetodo $autorizacion)
    {
        $autorizacion->update($request->validated());

        return new AutorizacionMetodoResource($autorizacion->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/autorizaciones-metodo/{autorizacion}
    public function destroy(AutorizacionMetodo $autorizacion): JsonResponse
    {
        // BAJA LOGICA (se revoca la autorizacion): queda el registro de que alguna vez existio.
        $autorizacion->update(['AutorizacionMetodoEstado' => false]);

        return response()->json(['mensaje' => 'Autorización revocada.'], 200);
    }
}
