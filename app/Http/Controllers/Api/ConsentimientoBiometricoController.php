<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsentimientoBiometricoRequest;
use App\Http\Resources\ConsentimientoBiometricoResource;
use App\Models\Biometria\ConsentimientoBiometrico;
use App\Services\ConsentimientoBiometricoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;

// Historial inmutable: solo index, show y store (cada fila es un evento de aceptacion o revocacion).
class ConsentimientoBiometricoController extends Controller
{
    private const RELACIONES = [
        'trabajador:TrabajadorId,TrabajadorNumeroDocumento,TrabajadorNombreCompleto',
        'documento:DocumentoSustentoId,DocumentoSustentoNombre',
    ];

    // GET /api/consentimientos-biometricos?buscar=quispe&trabajador_id=1&aceptado=1&vigente=1&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $consentimientos = ConsentimientoBiometrico::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->whereHas('trabajador', fn ($t) => $t
                ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))
            ->when($request->filled('trabajador_id'), fn ($q) => $q->where('TrabajadorId', $request->integer('trabajador_id')))
            ->when($request->filled('aceptado'), fn ($q) => $q->where('ConsentimientoBiometricoAceptado', $request->boolean('aceptado')))
            // El vigente de cada trabajador es su ultimo evento.
            ->when($request->boolean('vigente'), fn ($q) => $q->whereIn('ConsentimientoBiometricoId', ConsentimientoBiometrico::query()
                ->selectRaw('MAX(ConsentimientoBiometricoId)')->groupBy('TrabajadorId')))
            ->orderByDesc('ConsentimientoBiometricoFecha')
            ->orderByDesc('ConsentimientoBiometricoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        $this->marcarVigentes($consentimientos);

        return ConsentimientoBiometricoResource::collection($consentimientos);
    }

    // POST /api/consentimientos-biometricos
    public function store(ConsentimientoBiometricoRequest $request, ConsentimientoBiometricoService $servicio): JsonResponse
    {
        ['consentimiento' => $consentimiento, 'plantillas_desactivadas' => $desactivadas] = $servicio->registrar($request->validated());

        $consentimiento->load(self::RELACIONES)->setAttribute('es_vigente', true);

        return (new ConsentimientoBiometricoResource($consentimiento))
            ->additional(['plantillas_desactivadas' => $desactivadas])
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/consentimientos-biometricos/{consentimiento}
    public function show(ConsentimientoBiometrico $consentimiento)
    {
        $consentimiento->load(self::RELACIONES);
        $consentimiento->setAttribute('es_vigente', ConsentimientoBiometrico::vigenteDe($consentimiento->TrabajadorId)?->getKey() === $consentimiento->getKey());

        return new ConsentimientoBiometricoResource($consentimiento);
    }

    /** Marca en cada fila de la pagina si es el ultimo evento de su trabajador (una consulta para toda la pagina). */
    private function marcarVigentes(AbstractPaginator $pagina): void
    {
        $ultimos = ConsentimientoBiometrico::query()
            ->whereIn('TrabajadorId', $pagina->getCollection()->pluck('TrabajadorId')->unique())
            ->selectRaw('MAX(ConsentimientoBiometricoId) AS UltimoId')
            ->groupBy('TrabajadorId')
            ->pluck('UltimoId')
            ->map(fn ($id) => (int) $id);

        $pagina->getCollection()->each(fn ($c) => $c->setAttribute('es_vigente', $ultimos->contains($c->getKey())));
    }
}
