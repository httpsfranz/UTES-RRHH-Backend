<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrabajadorRequest;
use App\Http\Resources\TrabajadorResource;
use App\Models\Personal\Trabajador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrabajadorController extends Controller
{
    // GET /api/trabajadores?buscar=perez|12345678&tipo_documento_id=1&profesion_id=2&sexo=F&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $trabajadores = Trabajador::query()
            ->with(['tipoDocumento:TipoDocumentoIdentidadId,TipoDocumentoIdentidadCodigo,TipoDocumentoIdentidadNombre,TipoDocumentoIdentidadAbreviatura', 'profesion:ProfesionId,ProfesionNombre'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorCorreo', 'like', "%{$buscar}%")))
            ->when($request->filled('tipo_documento_id'), fn ($q) => $q->where('TipoDocumentoIdentidadId', $request->integer('tipo_documento_id')))
            ->when($request->filled('profesion_id'), fn ($q) => $q->where('ProfesionId', $request->integer('profesion_id')))
            ->when($request->filled('sexo'), fn ($q) => $q->where('TrabajadorSexo', $request->string('sexo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('TrabajadorEstado', $request->boolean('estado')))
            ->orderBy('TrabajadorApellidoPaterno')
            ->orderBy('TrabajadorApellidoMaterno')
            ->orderBy('TrabajadorNombres')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return TrabajadorResource::collection($trabajadores);
    }

    // POST /api/trabajadores
    public function store(TrabajadorRequest $request): JsonResponse
    {
        $trabajador = Trabajador::create($request->validated());

        // fresh(): trae lo que calcula la base (nombre completo, fecha de registro, Estado por DEFAULT).
        return (new TrabajadorResource($trabajador->fresh()->load('tipoDocumento', 'profesion')))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/trabajadores/{trabajador}
    public function show(Trabajador $trabajador)
    {
        return new TrabajadorResource($trabajador->load('tipoDocumento', 'profesion'));
    }

    // PUT|PATCH /api/trabajadores/{trabajador}
    public function update(TrabajadorRequest $request, Trabajador $trabajador)
    {
        $trabajador->update($request->validated());

        return new TrabajadorResource($trabajador->fresh()->load('tipoDocumento', 'profesion'));
    }

    // DELETE /api/trabajadores/{trabajador}
    public function destroy(Trabajador $trabajador): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: vinculos laborales, asistencia y usuarios cuelgan del trabajador
        // y el Reglamento obliga a conservar el historial.
        $trabajador->update(['TrabajadorEstado' => false]);

        return response()->json(['mensaje' => 'Trabajador desactivado.'], 200);
    }
}
