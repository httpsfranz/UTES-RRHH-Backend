<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstadoAsistenciaRequest;
use App\Models\Asistencia\EstadoAsistencia;
use Illuminate\Http\Request;

class EstadoAsistenciaController extends Controller
{
    // GET /api/estados-asistencia?buscar=falta&estado=1
    public function index(Request $request)
    {
        $query = EstadoAsistencia::query();

        if ($request->filled('buscar')) {
            $b = $request->buscar;

            $query->where(fn ($q) => $q
                ->where('EstadoAsistenciaNombre', 'like', "%{$b}%")
                ->orWhere('EstadoAsistenciaCodigo', 'like', "%{$b}%"));
        }

        if ($request->has('estado')) {
            $query->where(
                'EstadoAsistenciaEstado',
                $request->boolean('estado')
            );
        }

        return response()->json(
            $query->orderBy('EstadoAsistenciaNombre')->paginate(15)
        );
    }

    // POST /api/estados-asistencia
    public function store(EstadoAsistenciaRequest $request)
    {
        $estadoAsistencia = EstadoAsistencia::create(
            $request->validated()
        );

        return response()->json(
            $estadoAsistencia->fresh(),
            201
        );
    }

    // GET /api/estados-asistencia/{id}
    public function show(EstadoAsistencia $estadoAsistencia)
    {
        return response()->json($estadoAsistencia);
    }

    // PUT/PATCH /api/estados-asistencia/{id}
    public function update(
        EstadoAsistenciaRequest $request,
        EstadoAsistencia $estadoAsistencia
    ) {
        $estadoAsistencia->update(
            $request->validated()
        );

        return response()->json(
            $estadoAsistencia->fresh()
        );
    }

    // DELETE /api/estados-asistencia/{id} -> baja lógica
    public function destroy(EstadoAsistencia $estadoAsistencia)
    {
        $estadoAsistencia->update([
            'EstadoAsistenciaEstado' => false,
        ]);

        return response()->json([
            'message' => 'Estado de asistencia desactivado correctamente',
        ]);
    }
}