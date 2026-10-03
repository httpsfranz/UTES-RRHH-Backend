<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioAmbitoRequest;
use App\Http\Resources\UsuarioAmbitoResource;
use App\Models\Seguridad\UsuarioAmbito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioAmbitoController extends Controller
{
    private const RELACIONES = [
        'usuario:UsuarioId,UsuarioNombre,TrabajadorId',
        'usuario.trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'microred:MicroredId,MicroredCodigo,MicroredNombre',
        'eess:EessId,EessCodigo,EessNombre',
    ];

    // GET /api/usuarios-ambitos?buscar=...&usuario_id=&microred_id=&eess_id=&estado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = UsuarioAmbito::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('usuario', fn ($t) => $t
                    ->where('UsuarioNombre', 'like', "%{$buscar}%")
                    ->orWhereHas('trabajador', fn ($w) => $w
                        ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                        ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('microred_id'), fn ($q) => $q->where('MicroredId', $request->integer('microred_id')))
            ->when($request->filled('eess_id'), fn ($q) => $q->where('EessId', $request->integer('eess_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('UsuarioAmbitoEstado', $request->boolean('estado')))

            ->orderBy('UsuarioId')
            ->orderBy('UsuarioAmbitoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return UsuarioAmbitoResource::collection($registros);
    }

    // POST /api/usuarios-ambitos
    public function store(UsuarioAmbitoRequest $request): JsonResponse
    {
        $ambito = UsuarioAmbito::create($request->datos());

        return (new UsuarioAmbitoResource($ambito->fresh()->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/usuarios-ambitos/{ambito}
    public function show(UsuarioAmbito $ambito)
    {
        return new UsuarioAmbitoResource($ambito->load(self::RELACIONES));
    }

    // PUT|PATCH /api/usuarios-ambitos/{ambito}
    public function update(UsuarioAmbitoRequest $request, UsuarioAmbito $ambito)
    {
        $ambito->update($request->datos());

        return new UsuarioAmbitoResource($ambito->fresh()->load(self::RELACIONES));
    }

    // DELETE /api/usuarios-ambitos/{ambito}
    public function destroy(UsuarioAmbito $ambito): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: el historial se conserva.
        $ambito->update(['UsuarioAmbitoEstado' => false]);

        return response()->json(['mensaje' => 'Ámbito desactivado.'], 200);
    }
}
