<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EstablecimientoSaludResource;
use App\Models\Organizacion\EstablecimientoSalud;
use Illuminate\Http\Request;

// Solo lectura, por ahora: sirve de catalogo de consulta para los modulos de Nivel 0 que
// apuntan a un establecimiento (p. ej. Biometria.DispositivoMarcacion). El CRUD completo
// llega con el modulo M01 (Organizacion).
class EstablecimientoSaludController extends Controller
{
    // GET /api/establecimientos?buscar=esperanza&microred_id=1&estado=1&por_pagina=100
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $establecimientos = EstablecimientoSalud::query()
            ->with(['microred:MicroredId,MicroredNombre', 'tipoEstablecimiento:TipoEstablecimientoId,TipoEstablecimientoNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('EessNombre', 'like', "%{$buscar}%")
                ->orWhere('EessCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('microred_id'), fn ($q) => $q->where('MicroredId', $request->integer('microred_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('EessEstado', $request->boolean('estado')))
            ->orderBy('EessNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return EstablecimientoSaludResource::collection($establecimientos);
    }

    // GET /api/establecimientos/{establecimiento}
    public function show(EstablecimientoSalud $establecimiento)
    {
        return new EstablecimientoSaludResource($establecimiento->load('microred', 'tipoEstablecimiento'));
    }
}
