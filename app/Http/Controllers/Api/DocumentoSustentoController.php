<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentoSustentoRequest;
use App\Http\Resources\DocumentoSustentoResource;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoSustentoController extends Controller
{
    private const DISCO = 'local';

    // GET /api/documentos-sustento?buscar=constancia&tipo=application/pdf
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $documentos = DocumentoSustento::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where('DocumentoSustentoNombre', 'like', "%{$buscar}%"))
            ->when($request->filled('tipo'), fn ($q) => $q->where('DocumentoSustentoTipo', $request->string('tipo')->toString()))
            ->orderByDesc('DocumentoSustentoFechaRegistro')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return DocumentoSustentoResource::collection($documentos);
    }

    // POST /api/documentos-sustento  (multipart: `archivo`; tambien acepta JSON con los metadatos)
    public function store(DocumentoSustentoRequest $request): JsonResponse
    {
        $documento = DocumentoSustento::create($this->datos($request));

        return (new DocumentoSustentoResource($documento->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/documentos-sustento/{documento}
    public function show(DocumentoSustento $documento)
    {
        return new DocumentoSustentoResource($documento);
    }

    // GET /api/documentos-sustento/{documento}/archivo?descargar=1  (sin `descargar` se muestra en el navegador)
    public function archivo(Request $request, DocumentoSustento $documento): StreamedResponse
    {
        abort_unless(filled($documento->DocumentoSustentoRuta) && Storage::disk(self::DISCO)->exists($documento->DocumentoSustentoRuta), 404, 'El documento no tiene un archivo guardado.');

        $cabeceras = ['Content-Type' => $documento->DocumentoSustentoTipo ?: 'application/octet-stream'];

        return $request->boolean('descargar')
            ? Storage::disk(self::DISCO)->download($documento->DocumentoSustentoRuta, $documento->DocumentoSustentoNombre, $cabeceras)
            : Storage::disk(self::DISCO)->response($documento->DocumentoSustentoRuta, $documento->DocumentoSustentoNombre, $cabeceras);
    }

    // PUT|PATCH /api/documentos-sustento/{documento}  (un archivo nuevo reemplaza al anterior)
    public function update(DocumentoSustentoRequest $request, DocumentoSustento $documento)
    {
        $anterior = $documento->DocumentoSustentoRuta;
        $documento->update($this->datos($request));
        if ($request->hasFile('archivo') && filled($anterior) && $anterior !== $documento->DocumentoSustentoRuta) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return new DocumentoSustentoResource($documento->fresh());
    }

    // DELETE /api/documentos-sustento/{documento}
    public function destroy(DocumentoSustento $documento): JsonResponse
    {
        // La FK bloquea el DELETE de un documento que ya respalda una solicitud o un expediente.
        $ruta = $documento->DocumentoSustentoRuta;
        $documento->delete();
        if (filled($ruta)) {
            Storage::disk(self::DISCO)->delete($ruta);
        }

        return response()->json(['mensaje' => 'Documento eliminado.'], 200);
    }

    /**
     * Columnas a guardar. Con un archivo, nombre, tipo, extension, tamano y hash salen del propio archivo.
     *
     * @return array<string,mixed>
     */
    private function datos(DocumentoSustentoRequest $request): array
    {
        $datos = collect($request->validated())->except('archivo')->all();
        if (! $request->hasFile('archivo')) {
            return $datos;
        }

        /** @var UploadedFile $archivo */
        $archivo = $request->file('archivo');
        $hash = hash_file('sha256', $archivo->getRealPath());
        abort_if(DocumentoSustento::query()->where('DocumentoSustentoHash', $hash)->when($request->route('documento'), fn ($q, $d) => $q->where('DocumentoSustentoId', '<>', $d->DocumentoSustentoId))->exists(), 422, 'Este archivo ya fue registrado como documento de sustento.');

        return [
            'DocumentoSustentoNombre' => $datos['DocumentoSustentoNombre'] ?? $archivo->getClientOriginalName(),
            'DocumentoSustentoRuta' => $archivo->store('documentos/'.date('Y/m'), self::DISCO),
            'DocumentoSustentoTipo' => $archivo->getMimeType(),
            'DocumentoSustentoExtension' => strtolower($archivo->getClientOriginalExtension()),
            'DocumentoSustentoTamanoBytes' => $archivo->getSize(),
            'DocumentoSustentoHash' => $hash,
        ];
    }
}
