<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlantillaBiometricaRequest;
use App\Http\Resources\PlantillaBiometricaResource;
use App\Models\Biometria\PlantillaBiometrica;
use App\Services\PlantillaBiometricaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// La referencia binaria no se lee jamas desde aqui (scope sinReferencia): solo se informa si existe.
class PlantillaBiometricaController extends Controller
{
    private const RELACIONES = ['trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto'];

    // GET /api/plantillas-biometricas?buscar=quispe&trabajador_id=1&tipo=ROSTRO&estado=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $plantillas = PlantillaBiometrica::query()
            ->sinReferencia()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->whereHas('trabajador', fn ($t) => $t
                ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('tipo'), fn ($q) => $q->where('PlantillaBiometricaTipo', $request->string('tipo')->toString()))
            ->when($request->filled('estado'), fn ($q) => $q->where('PlantillaBiometricaEstado', $request->boolean('estado')))
            ->orderBy('TrabajadorId')
            ->orderBy('PlantillaBiometricaTipo')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return PlantillaBiometricaResource::collection($plantillas);
    }

    // POST /api/plantillas-biometricas  (PlantillaBiometricaReferencia = base64, opcional)
    public function store(PlantillaBiometricaRequest $request, PlantillaBiometricaService $servicio): JsonResponse
    {
        $datos = $request->validated();
        $referencia = $datos['PlantillaBiometricaReferencia'] ?? null;
        unset($datos['PlantillaBiometricaReferencia']);

        $plantilla = $servicio->guardar(null, $datos, $referencia);

        return (new PlantillaBiometricaResource($plantilla->load(self::RELACIONES)))
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/plantillas-biometricas/{plantilla}
    public function show(PlantillaBiometrica $plantilla)
    {
        return new PlantillaBiometricaResource($plantilla->load(self::RELACIONES));
    }

    // PUT|PATCH /api/plantillas-biometricas/{plantilla}
    public function update(PlantillaBiometricaRequest $request, PlantillaBiometrica $plantilla, PlantillaBiometricaService $servicio)
    {
        $datos = $request->validated();
        $referencia = $datos['PlantillaBiometricaReferencia'] ?? null;
        unset($datos['PlantillaBiometricaReferencia']);

        return new PlantillaBiometricaResource($servicio->guardar($plantilla, $datos, $referencia)->load(self::RELACIONES));
    }

    // DELETE /api/plantillas-biometricas/{plantilla}
    public function destroy(PlantillaBiometrica $plantilla): JsonResponse
    {
        // BAJA LOGICA, no DELETE fisico: Asistencia.Marcacion referencia la plantilla con la que se marco.
        $plantilla->update(['PlantillaBiometricaEstado' => false]);

        return response()->json(['mensaje' => 'Plantilla biométrica desactivada.'], 200);
    }
}
