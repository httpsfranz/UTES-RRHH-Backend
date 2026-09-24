<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentoSustentoRequest;
use App\Http\Resources\DocumentoSustentoResource;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentoSustentoController extends Controller
{
    // GET /api/documentos-sustento?buscar=constancia&tipo=application/pdf
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $documentos = DocumentoSustento::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where('DocumentoSustentoNombre', 'like', "%{$buscar}%"))
            ->when($request->filled('tipo'), fn ($q) =>
                $q->where('DocumentoSustentoTipo', $request->string('tipo')->toString()))
            ->orderByDesc('DocumentoSustentoFechaRegistro')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DocumentoSustentoResource::collection($documentos);
    }

    // POST /api/documentos-sustento
    public function store(DocumentoSustentoRequest $request): JsonResponse
    {
        $documento = DocumentoSustento::create($request->validated());

        return (new DocumentoSustentoResource($documento->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/documentos-sustento/{documento}
    public function show(DocumentoSustento $documento)
    {
        return new DocumentoSustentoResource($documento);
    }

    // PUT|PATCH /api/documentos-sustento/{documento}
    public function update(DocumentoSustentoRequest $request, DocumentoSustento $documento)
    {
        $documento->update($request->validated());

        return new DocumentoSustentoResource($documento->fresh());
    }

    // DELETE /api/documentos-sustento/{documento}
    public function destroy(DocumentoSustento $documento): JsonResponse
    {
        // Esta tabla no tiene columna de Estado. Hoy nada la referencia via FK
        // (ningun modulo de Papeleta/Licencia/PAD esta construido todavia), asi que
        // se elimina fisicamente; cuando esos modulos existan, la FK bloqueara el
        // DELETE de un documento en uso, que es el comportamiento correcto.
        $documento->delete();

        return response()->json(['mensaje' => 'Documento eliminado.'], 200);
    }
}
