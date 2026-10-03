<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SesionAccesoResource;
use App\Models\Seguridad\SesionAcceso;
use Illuminate\Http\Request;

class SesionAccesoController extends Controller
{
    private const RELACIONES = [
        'usuario:UsuarioId,UsuarioNombre',
    ];

    // GET /api/sesiones-acceso?buscar=...&usuario_id=&resultado=
    //     &desde=AAAA-MM-DD&hasta=AAAA-MM-DD&por_pagina=15
    public function index(Request $request)
    {
        $buscar = $request->string('buscar')->toString();

        $registros = SesionAcceso::query()
            ->with(self::RELACIONES)
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s
                ->where('SesionAccesoDireccionIp', 'like', "%{$buscar}%")
                ->orWhere('SesionAccesoResultado', 'like', "%{$buscar}%")
                ->orWhereHas('usuario', fn ($t) => $t
                    ->where('UsuarioNombre', 'like', "%{$buscar}%")
                    ->orWhereHas('trabajador', fn ($w) => $w
                        ->where('TrabajadorNombreCompleto', 'like', "%{$buscar}%")
                        ->orWhere('TrabajadorNumeroDocumento', 'like', "%{$buscar}%")))))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('resultado'), fn ($q) => $q->where('SesionAccesoResultado', $request->string('resultado')->toString()))

            ->when($request->filled('desde'), fn ($q) => $q->whereDate('SesionAccesoFechaInicio', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('SesionAccesoFechaInicio', '<=', $request->date('hasta')))
            ->orderByDesc('SesionAccesoFechaInicio')
            ->orderByDesc('SesionAccesoId')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100)));

        return SesionAccesoResource::collection($registros);
    }

    // GET /api/sesiones-acceso/{sesion}
    public function show(SesionAcceso $sesion)
    {
        return new SesionAccesoResource($sesion->load(self::RELACIONES));
    }
}
