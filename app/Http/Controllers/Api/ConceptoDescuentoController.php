<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConceptoDescuentoRequest;
use App\Http\Resources\ConceptoDescuentoResource;
use App\Models\Compensaciones\ConceptoDescuento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConceptoDescuentoController extends Controller
{
    
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();
        $conceptos = ConceptoDescuento::query()
            ->when($request->filled('buscar'), fn ($q) =>
                $q->where(fn ($s) => $s
                    ->where('ConceptoDescuentoNombre', 'like', "%{$buscar}%")
                    ->orWhere('ConceptoDescuentoCodigo', 'like', "%{$buscar}%")))
            ->when($request->filled('estado'), fn ($q) =>
                $q->where('ConceptoDescuentoEstado', $request->boolean('estado')))
            ->orderBy('ConceptoDescuentoNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return ConceptoDescuentoResource::collection($conceptos);
    }

    
    public function store(ConceptoDescuentoRequest $request): JsonResponse
    {
        $concepto = ConceptoDescuento::create($request->validated());
        return (new ConceptoDescuentoResource($concepto->fresh()))
        ->response()
        ->setStatusCode(201);
    }

    
    public function show(ConceptoDescuento $concepto)
    {
        return new ConceptoDescuentoResource ($concepto);
    }

    
    public function update(ConceptoDescuentoRequest $request, ConceptoDescuento $concepto)
    {
        $concepto->update($request->validated());
        return new ConceptoDescuentoResource($concepto->fresh());
    }

   
    public function destroy(ConceptoDescuento $concepto): JsonResponse
    {
        $concepto->update(['ConceptoDescuentoEstado' => 0]);
        return response()->json(['mensaje'=>'Concepto de descuento desactivado'],200);
    }
}
